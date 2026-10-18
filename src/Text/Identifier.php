<?php

namespace OpenSim\Text;

use Error;

/**
 * Text made to be an identifier: an id, a class, a slug, a version.
 */
final class Identifier
{
    /**
     * Sanitize a string to be used as an ID or slug, like for html container id, css class, etc.
     */
    public static function id($string)
    {
        if (empty($string)) {
            return false;
        }

        $id = $string;
        try {
            // Transliterate to Latin, remove diacritics, lowercase, but keep dashes and underscores
            $id = @transliterator_transliterate('Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Lower();', $id);
        } catch (Error $e) {
            error_log('Warning (php-intl missing) ' . $e->getMessage());
            $id = strtolower($id);
        }
        // Remove all punctuation except dashes and underscores
        $id = preg_replace('/[^\p{L}\p{N}_\-\s]/u', '-', $id);
        // Replace multiple separators with a single one
        $sep = '[-_\s]';
        $id = preg_replace("/$sep* $sep*/", '_', $id);
        $id = preg_replace("/$sep*_$sep*/", '_', $id);
        $id = preg_replace("/$sep*-$sep*/", '-', $id);
        // Remove leading/trailing dashes
        $id = trim($id, '-');

        return $id;
    }

    public static function version($string)
    {
        if (empty($string)) {
            return false;
        }

        $id = $string;
        try {
            // Transliterate to Latin, remove diacritics, lowercase, but keep dashes and underscores
            $id = @transliterator_transliterate('Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Lower();', $id);
        } catch (Error $e) {
            error_log('Warning (php-intl missing) ' . $e->getMessage());
            $id = strtolower($id);
        }
        // Remove all punctuation except dashes and underscores
        $id = preg_replace('/[^\p{L}\p{N}_\-\s\.]/u', '-', $id);
        // Replace multiple separators with a single one
        $sep = '[-_\s]';
        $id = preg_replace("/$sep* $sep*/", '_', $id);
        $id = preg_replace("/$sep*_$sep*/", '_', $id);
        $id = preg_replace("/$sep*-$sep*/", '-', $id);
        // Remove leading/trailing dashes
        $id = trim($id, '-');

        return $id;
    }
}
