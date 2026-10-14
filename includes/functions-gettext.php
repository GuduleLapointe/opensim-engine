<?php
/**
 * The gettext functions the projects use, defined when the extension is missing: they return the message untranslated.
 */

if (!function_exists('gettext')) {
    function gettext(string $message): string
    {
        return $message;
    }
}

if (!function_exists('_')) {
    function _(string $message): string
    {
        return $message;
    }
}

if (!function_exists('ngettext')) {
    function ngettext(string $singular, string $plural, int $count): string
    {
        return $count === 1 ? $singular : $plural;
    }
}

if (!function_exists('bindtextdomain')) {
    function bindtextdomain(string $domain, ?string $directory): string|false
    {
        return $directory ?? false;
    }
}

if (!function_exists('bind_textdomain_codeset')) {
    function bind_textdomain_codeset(string $domain, ?string $codeset): string|false
    {
        return $codeset ?? false;
    }
}

if (!function_exists('textdomain')) {
    function textdomain(?string $domain): string
    {
        return $domain ?? 'messages';
    }
}
