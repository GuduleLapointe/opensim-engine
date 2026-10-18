<?php

namespace OpenSim\Text;

/**
 * Text encodings.
 */
final class Encoding
{
    /**
     * Replace the characters ISO-8859-1 cannot represent by "?".
     *
     * Replaces utf8_encode( utf8_decode( $text ) ), deprecated since PHP 8.2.
     *
     * @param  string $text The text to clean.
     * @return string
     */
    public static function latin1Only($text)
    {
        return mb_convert_encoding(mb_convert_encoding((string) $text, 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1');
    }

    public static function utf8($string)
    {
        // Ensure the string is in UTF-8 encoding
        if (!mb_check_encoding($string, 'UTF-8')) {
            $string = mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
        }
        return $string;
    }
}
