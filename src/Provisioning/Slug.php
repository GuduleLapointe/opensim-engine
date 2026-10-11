<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

/**
 * Derives the nick and the slug of a grid, or the name of an instance, from a display name: snake_case, lowercase letters,
 * digits and underscores, the words kept apart so that "The Rapist" (the_rapist) is not "Therapist" (therapist).
 */
final class Slug
{
    /** The nick of a grid: its slug ("Olivier's grid" -> "oliviers_grid"). */
    public static function nick(string $name): string
    {
        return self::slug($name);
    }

    /**
     * The slug: snake_case. A name written in camel case keeps its words apart (OliviersGrid), an apostrophe is dropped, not
     * a separator ("Olivier's" is "oliviers"), any other run of characters is one underscore.
     */
    public static function slug(string $name): string
    {
        $ascii = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', self::ascii($name)));
        $ascii = str_replace(["'", "\u{2019}"], '', $ascii);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $ascii) ?? '';

        return trim($slug, '_');
    }

    /** The name of an instance, as the launcher names it: the slug ("Speculoos Welcome" -> "speculoos_welcome"). */
    public static function instance(string $name): string
    {
        return self::slug($name);
    }

    /** Transliterate to ASCII (intl when available, iconv as a fallback): "Joyeux Noël" -> "Joyeux Noel". */
    public static function ascii(string $value): string
    {
        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($transliterator !== null) {
                $out = $transliterator->transliterate($value);
                if ($out !== false) {
                    return $out;
                }
            }
        }

        $out = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

        return $out !== false ? $out : $value;
    }
}
