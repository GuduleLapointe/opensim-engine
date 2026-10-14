<?php

declare(strict_types=1);

namespace OpenSim\Network;

/**
 * A request to a service of a grid over HTTP: what the answer says, nothing when there is none (the service does not answer, or
 * not with a success), so that a caller tells a silent service from a refusal.
 */
final class Http
{
    /**
     * @param array<string,scalar>|string $body the fields of a form, or the body as it is
     * @param list<string> $headers
     */
    public static function post(string $url, array|string $body, array $headers = [], int $timeout = 5): ?string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => is_array($body) ? http_build_query($body) : $body,
            CURLOPT_HTTPHEADER => $headers ?: ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        return self::answer($handle, curl_exec($handle));
    }

    public static function get(string $url, int $timeout = 5): ?string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT => $timeout,
        ]);

        return self::answer($handle, curl_exec($handle));
    }

    private static function answer(\CurlHandle $handle, string|bool $body): ?string
    {
        // The handle closes itself when it goes out of scope
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return is_string($body) && $status >= 200 && $status < 300 ? $body : null;
    }
}
