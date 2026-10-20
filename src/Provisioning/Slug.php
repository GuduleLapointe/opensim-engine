<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

/**
 * The names derived from a display name. Lower case letters and digits, the words kept apart so that "The Rapist" (the_rapist) is
 * not "Therapist" (therapist):
 *
 *  - the nick of a grid, and the name of a simulator in its instance, are snake_case with the hyphens of the name kept
 *    ("Mary-Jane World" is `mary-jane_world`, not the same as "Mary Jane-World", `mary_jane-world`);
 *  - the instance of a simulator is its grid, a dot, its own name: `mary-jane_world.welcome_area`. The dot separates what the
 *    underscore and the hyphen cannot: "Welcome" on "My World" is `my_world.welcome`, "World Welcome" on "My" is `my.world_welcome`;
 *  - a slug is kebab-case (`green-field`), for a path or an address of one thing;
 *  - a name for a table or a database is snake_case (`green_field`), where neither a hyphen nor a dot can be.
 */
final class Slug
{
    /** The separator of the grid and the simulator in an instance. */
    public const SEPARATOR = '.';

    /**
     * The nick of a grid, and the name of a simulator in its instance: snake_case, the hyphens inside a word kept ("Mary-Jane World"
     * is `mary-jane_world`, "Olivier's grid" is `oliviers_grid`). A name written in camel case keeps its words apart.
     */
    public static function nick(string $name): string
    {
        $ascii = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', self::ascii($name)));
        $ascii = str_replace(["'", "\u{2019}"], '', $ascii);
        // A hyphen between two letters or digits is part of the word; the others separate words
        $ascii = (string) preg_replace('/(?<=[a-z0-9])-(?=[a-z0-9])/', "\x01", $ascii);
        $words = trim((string) preg_replace('/[^a-z0-9\x01]+/', '_', $ascii), '_');

        return str_replace("\x01", '-', $words);
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
     * The instance of a simulator: its grid, a dot, its own name ("Mary-Jane World", "Welcome Area" ->
     * `mary-jane_world.welcome_area`). Without the grid, the welcome of a grid would be the welcome of another.
     */
    public static function simulator(string $grid, string $name): string
    {
        return self::nick($grid) . self::SEPARATOR . self::nick($name);
    }

    /**
     * The instances a simulator of a grid may have, the one made now first and then the ones made before (the grid, an underscore,
     * the name as a slug or in snake_case: `wonderful-green_field`, `wonderful_green_field`).
     *
     * @return list<string>
     */
    public static function simulatorCandidates(string $grid, string $name): array
    {
        return array_values(
            array_unique([
                self::simulator($grid, $name),
                self::slug($grid) . '_' . self::slug($name),
                self::snake($grid . '_' . $name),
            ]),
        );
    }

    /**
     * What is left of the instance of a simulator without its grid (the name the person gave it, as the instance has it). The
     * instances made before, with an underscore after the grid, are understood.
     */
    public static function simulatorOf(string $slug, string $nick): string
    {
        foreach ([self::nick($nick) . self::SEPARATOR, self::slug($nick) . '_', self::snake($nick) . '_'] as $prefix) {
            if (str_starts_with($slug, $prefix)) {
                return substr($slug, strlen($prefix));
            }
        }

        return $slug;
    }

    /** The name a nick suggests, when nothing says better ("watta_world" is "Watta World", "mary-jane_world" is "Mary-Jane World"). */
    public static function nameOf(string $nick): string
    {
        $words = preg_split('/[^A-Za-z0-9-]+/', $nick, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_map(
            static fn(string $word): string => implode('-', array_map('ucfirst', explode('-', $word))),
            $words,
        );

        return $words === [] ? ucfirst($nick) : implode(' ', $words);
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
