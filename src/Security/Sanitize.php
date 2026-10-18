<?php

namespace OpenSim\Security;

/**
 * What comes in is made safe before it is used: filters for the simple values, tags and attributes taken out of the HTML.
 */
final class Sanitize
{
    public static function url($url)
    {
        $url = trim($url);
        $url = str_replace(' ', '+', $url);
        $url = filter_var($url, FILTER_SANITIZE_URL);
        return $url;
    }

    public static function email($email)
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }

    public static function filterUrl($url)
    {
        return filter_var($url, FILTER_SANITIZE_URL);
    }

    public static function key($key)
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
    }

    public static function int($value)
    {
        return filter_var($value, FILTER_SANITIZE_NUMBER_INT);
    }

    public static function float($value)
    {
        return filter_var($value, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    }

    public static function html($html, $allowed_tags = null)
    {
        if ($allowed_tags === null) {
            // Default allowed tags for descriptions: basic formatting only
            $allowed_tags = '<p><br><strong><b><em><i><u><code><pre><a><span><div><ul><ol><li><blockquote>';
        }

        // Strip dangerous tags but keep allowed formatting
        $clean_html = strip_tags($html, $allowed_tags);

        // Remove dangerous attributes from allowed tags
        // One attribute per pass: a tag with several is cleaned until there is none left
        do {
            $before = $clean_html;
            $clean_html = preg_replace(
                '/(<[^>]*)\s(on\w+|style|class)\s*=\s*["\'][^"\']*["\']([^>]*>)/i',
                '$1$3',
                $clean_html,
            );
        } while ($clean_html !== $before);

        return trim($clean_html);
    }

    public static function richText($html)
    {
        // For rich content areas - more permissive
        $allowed_tags =
            '<p><br><strong><b><em><i><u><code><pre><a><span><div><h1><h2><h3><h4><h5><h6><ul><ol><li><blockquote>';
        return self::html($html, $allowed_tags);
    }

    public static function basicHtml($html)
    {
        // For basic formatting only
        $allowed_tags = '<strong><b><em><i><u><code><a>';
        return self::html($html, $allowed_tags);
    }
}
