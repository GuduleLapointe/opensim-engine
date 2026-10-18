<?php

namespace OpenSim\Security;

use Laminas\Escaper\Escaper;

/**
 * What goes out is escaped for the place it goes to: the text of a page, an attribute, an address, a script, a style.
 */
final class Escape
{
    public static function escaper()
    {
        static $escaper = null;
        if ($escaper === null) {
            $escaper = new Escaper('utf-8');
        }
        return $escaper;
    }

    public static function html($text)
    {
        if (is_string($text)) {
            return self::escaper()->escapeHtml($text);
        }
    }

    public static function attr($text)
    {
        return self::escaper()->escapeHtmlAttr($text);
    }

    public static function url($url)
    {
        return self::escaper()->escapeUrl($url);
    }

    public static function js($text)
    {
        return self::escaper()->escapeJs($text);
    }

    public static function css($text)
    {
        return self::escaper()->escapeCss($text);
    }
}
