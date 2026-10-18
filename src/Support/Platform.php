<?php

namespace OpenSim\Support;

/**
 * What this PHP has.
 */
final class Platform
{
    public static function has($extension)
    {
        switch ($extension) {
            case 'php':
                return true; // PHP is always loaded
            case 'php7':
                return version_compare(PHP_VERSION, '7.0', '>=');
            case 'php7.4':
                return version_compare(PHP_VERSION, '7.4', '>=');
            case 'php8.0':
                return version_compare(PHP_VERSION, '8.0', '>=');
            case 'curl':
                return function_exists('curl_init');
            case 'intl':
                return function_exists('transliterator_transliterate');
            case 'xmlrpc':
                return function_exists('xmlrpc_encode');
            // return function_exists( 'xmlrpc_encode' ) || class_exists( '\\PhpXmlRpc\\Value' );
            case 'imagick':
                return class_exists('Imagick') || class_exists('ImagickDraw') || class_exists('ImagickPixel');
            case 'json':
                return function_exists('json_encode');
            case 'mbstring':
                return function_exists('mb_convert_encoding');
            case 'simplexml':
                return function_exists('simplexml_load_string');
            default:
                // For any other extension, we can use the extension_loaded function
                return extension_loaded($extension);
        }
        return false;
    }
}
