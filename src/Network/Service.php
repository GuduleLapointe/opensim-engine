<?php

namespace OpenSim\Network;

use Exception;

/**
 * Whether a service answers on its port.
 */
final class Service
{
    /**
     * Check service availability.
     *
     * Make a fast socket connection to the given host and port.
     *
     * @param  string $host   Hostname or IP address or URL, with or without port
     * @param  int    $port   Port number, optional if included in $host
     * @return boolean        True if the service is available, false otherwise
     */
    public static function available($host, $port = null)
    {
        if (empty($host)) {
            return false;
        }
        if (strpos($host, ':') !== false) {
            $parts = explode(':', $host);
            $port = array_pop($parts);
            $host = implode(':', $parts);
        }
        if (empty($port) || empty($host)) {
            error_log('[ERROR] self::available() requires a valid host and port');
            return false;
        }

        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$fp) {
            return new Exception("Failed to connect to $host:$port - $errstr");
            // return false;
        }

        fclose($fp);
        return true;
    }
}
