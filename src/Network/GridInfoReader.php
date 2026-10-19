<?php

declare(strict_types=1);

namespace OpenSim\Network;

/**
 * What a grid says of itself, as its running Robust says it: the elements of `/get_grid_info` (gridname, login, economy, search...),
 * the values of `[GridInfoService]` as the server holds them now, which a config changed since may not be. Nothing is made up: a grid
 * that does not answer says nothing.
 */
final class GridInfoReader
{
    /** Seconds to wait for the answer; the connection itself is given up after two. */
    private const TIMEOUT = 3;

    /**
     * @param string $login the login address of the grid (its public port), with its scheme
     * @param ?\Closure(string):?string $http the body of an address, null when it does not answer (default: curl, whatever the certificate)
     * @return array<string,string> element => value, empty when the grid does not answer
     */
    public static function read(string $login, ?\Closure $http = null): array
    {
        if (preg_match('#^https?://[^\s/]+#i', $login) !== 1) {
            return [];
        }
        $url = rtrim($login, '/') . '/get_grid_info';
        $body = $http !== null ? $http($url) : self::get($url);
        if (!is_string($body) || $body === '') {
            return [];
        }

        return self::parse($body);
    }

    /**
     * Every element of the answer, whatever its name: Robust gives all the keys of `[GridInfoService]`, the ones nobody planned
     * included, and an empty one is a value too. An element with others inside is its text.
     *
     * @return array<string,string>
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $said = [];
        if ($document !== false) {
            foreach ($document->children() as $name => $value) {
                $said[(string) $name] = trim((string) $value);
            }

            return $said;
        }

        // Not well formed: the elements that are there are read all the same
        if (preg_match_all('#<([A-Za-z_][\w.-]*)(?:\s*/>|>([^<]*)</\1>)#', $xml, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as $element) {
                $said[$element[1]] = trim(html_entity_decode($element[2] ?? '', ENT_QUOTES | ENT_XML1));
            }
        }

        return $said;
    }

    /** The body of an address, null when it does not answer with a 200 (the certificate is not checked). */
    public static function get(string $url): ?string
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            // What is asked here is what the grid says, whatever its certificate (the health checks say it)
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($handle);
        $ok = curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;

        return $ok && is_string($body) ? $body : null;
    }
}
