<?php

declare(strict_types=1);

namespace OpenSim\Network;

/**
 * HTTP requests to the services of a grid: the body of the answer, null when there is none or its status is not 2xx.
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
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return is_string($body) && $status >= 200 && $status < 300 ? $body : null;
    }
}
