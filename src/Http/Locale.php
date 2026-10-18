<?php

namespace OpenSim\Http;

/**
 * The language of the answer: the one the request asks for.
 */
final class Locale
{
    public static function negotiate($locale = null, $domain = 'messages')
    {
        mb_internal_encoding('UTF-8');
        $encoding = mb_internal_encoding();

        if (isset($_GET['l'])) {
            $locale = $_GET['l'];
        }
        $languages = array_filter(array_merge([$locale], explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));

        // $results = putenv("LC_ALL=$locale");
        // if (!$results) {
        // exit ('putenv failed');
        // }

        // $currentLocale = setlocale(LC_ALL, 0);
        $user_locales = array_unique([
            $locale,
            $locale . ".$encoding",
            $locale . '.UTF-8',
            $locale . '.utf8',
            $locale,
            0,
        ]);

        $user_locales = array_map(function ($code) {
            return preg_replace(['/;.*/', '/-/'], ['', '_'], $code);
        }, $languages);

        // Generate variants with different encodings appended
        $variants = [];
        foreach ($user_locales as $lang) {
            $variants[] = $lang;
            $variants[] = "$lang.$encoding";
            // $variants[] = "$lang.UTF-8";
        }

        $variants = array_unique($variants);
        $locale = setlocale(LC_ALL, $variants);
        if (!$locale) {
            // error_log( "setlocale() failed: none of  '" . join( ', ', $variants ) . "' does exist in this environment or setlocale() is not available on this platform" );
            $locale = setlocale(LC_ALL, 0);
            return 0;
        }

        bindtextdomain($domain, './locales');
        textdomain($domain);
        return $locale;
    }
}
