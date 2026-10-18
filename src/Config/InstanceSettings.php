<?php

declare(strict_types=1);

namespace OpenSim\Config;

/**
 * What an existing Robust or simulator config says now, in the names the planners use: the setup reads it to propose what is
 * already there.
 */
final class InstanceSettings
{
    /**
     * Current settings of an existing Robust or simulator config (tolerant
     * regex, comments and quotes ignored).
     *
     * @return array<string,string|int|list<string>>
     */
    public static function of(string $path): array
    {
        $text = GridFileService::read($path);
        $ini = ConfigReader::expand(ConfigReader::parse($text));
        $current = [];

        $grab = static fn(string $key): ?string => ConfigReader::find($ini, $key);

        foreach (['gridName' => 'gridname', 'baseHostname' => 'BaseHostname', 'webUrl' => 'WebURL'] as $field => $key) {
            $value = $grab($key);
            if ($value !== null) {
                $current[$field] = $value;
            }
        }
        foreach (
            ['publicPort' => 'PublicPort', 'privatePort' => 'PrivatePort', 'httpPort' => 'http_listener_port']
            as $field => $key
        ) {
            if (($port = $grab($key)) !== null && ctype_digit($port)) {
                $current[$field] = (int) $port;
            }
        }
        if (($db = ConnectionString::parse((string) $grab('ConnectionString'))) !== null) {
            $current['dbHost'] = $db->source;
            $current['dbName'] = $db->database;
            $current['dbUser'] = $db->user;
            $current['dbPass'] = $db->password;
        }
        // The remote console, when the config has one (its port is ConsolePort in Robust, console_port in a simulator)
        foreach (
            ['consoleHost' => 'ConsoleHost', 'consoleUser' => 'ConsoleUser', 'consolePass' => 'ConsolePass']
            as $field => $key
        ) {
            $value = $grab($key);
            if ($value !== null && $value !== '') {
                $current[$field] = $value;
            }
        }
        if (($port = $grab('ConsolePort') ?? $grab('console_port')) !== null && ctype_digit($port) && (int) $port > 0) {
            $current['consolePort'] = (int) $port;
        }
        $current['connectors'] = array_keys($ini['ServiceList'] ?? []);
        // The roles of the regions: Region_<Name> = "DefaultRegion, ..."
        if (($flags = \OpenSim\Models\Region::rolesIn($text)) !== []) {
            $current['regionFlags'] = $flags;
        }
        foreach (['estateName' => 'DefaultEstateName', 'estateOwner' => 'DefaultEstateOwnerName'] as $field => $key) {
            $value = $grab($key);
            if ($value !== null) {
                $current[$field] = $value;
            }
        }

        return $current;
    }
}
