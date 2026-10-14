<?php
/**
 * Gettext functions, for a PHP without the extension.
 *
 * The messages of the projects are English in the code and translated through gettext when the extension is there. Without it,
 * these functions stand in for the ones the projects use and give the message back as it is, so nothing has to be installed to
 * run them in English. Each one is defined only when PHP does not have it.
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
