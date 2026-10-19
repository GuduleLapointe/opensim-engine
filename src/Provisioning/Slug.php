<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

/**
 * The names derived from a display name. Lower case letters and digits, the words kept apart so that "The Rapist" (the-rapist) is
 * not "Therapist" (therapist):
 *
 *  - a slug is kebab-case (`green-field`), for a path, an address, an instance;
 *  - a name for a table or a database is snake_case (`green_field`), where a hyphen cannot be; so is the nick of a grid, which names
 *    its databases.
 *
 * The underscore does not separate the words of a slug: it separates the grid from the simulator in the slug of a simulator
 * (`vonda_welcome`). Every grid has a welcome, and several can be on a server: "Green Field" on "Wonderful" is
 * `wonderful_green-field`, "Field" on "Wonderful Green" is `wonderful-green_field`.
 */
final class Slug
{
    /** The nick of a grid: snake_case, as it names its folder and its databases ("Olivier's grid" -> "oliviers_grid"). */
    public static function nick(string $name): string
    {
        return self::snake($name);
    }

    /**
     * The slug: kebab-case. A name written in camel case keeps its words apart (OliviersGrid), an apostrophe is dropped, not a
     * separator ("Olivier's" is "oliviers"), any other run of characters is one hyphen.
     */
    public static function slug(string $name): string
    {
        return self::words($name, '-');
    }

    /** A name for a table or a database: snake_case ("Green Field" -> "green_field"). */
    public static function snake(string $name): string
    {
        return self::words($name, '_');
    }

    /**
     * The slug of a simulator: its grid, an underscore, its own slug ("Wonderful Green", "Field" -> "wonderful-green_field").
     * Without the grid, the welcome of a grid would be the welcome of another.
     */
    public static function simulator(string $grid, string $name): string
    {
        return self::slug($grid) . '_' . self::slug($name);
    }

    /**
     * What is left of the slug of a simulator without its grid (the name the person gave it, as a slug). Slugs made as snake_case
     * before ("wonderful_green_field") are understood.
     */
    public static function simulatorOf(string $slug, string $nick): string
    {
        foreach ([self::slug($nick) . '_', self::snake($nick) . '_'] as $prefix) {
            if (str_starts_with($slug, $prefix)) {
                return substr($slug, strlen($prefix));
            }
        }

        return $slug;
    }

    /** The name a nick suggests, when nothing says better ("watta_world" is "Watta World"). */
    public static function nameOf(string $nick): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $nick, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $words === [] ? ucfirst($nick) : implode(' ', array_map('ucfirst', $words));
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

    /**
     * A name in Latin letters, whatever alphabet it is written in: transliterated, then what a name cannot have removed (the words kept
     * apart by one space). A name that is ASCII already is returned as it is, for the rules of its model to say what is wrong with it.
     *
     * @param string $kept the punctuation the name may have besides letters, digits and the space
     */
    public static function latin(string $value, string $kept = ''): string
    {
        if ($value === '' || preg_match('/[^\x00-\x7F]/', $value) !== 1) {
            return $value;
        }
        $ascii = self::ascii($value);
        // The compatibility forms (a superscript n) become letters
        class_exists(\Normalizer::class) && ($ascii = \Normalizer::normalize($ascii, \Normalizer::FORM_KD) ?: $ascii);
        $clean = (string) preg_replace('/[^A-Za-z0-9 ' . preg_quote($kept, '/') . ']/', '', $ascii);
        $clean = trim((string) preg_replace('/ +/', ' ', $clean));

        return $clean !== '' ? $clean : $value;
    }

    private static function words(string $name, string $glue): string
    {
        $ascii = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', self::ascii($name)));
        $ascii = str_replace(["'", "\u{2019}"], '', $ascii);

        return trim(preg_replace('/[^a-z0-9]+/', $glue, $ascii) ?? '', $glue);
    }
}
