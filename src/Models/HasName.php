<?php

namespace OpenSim\Models;

use OpenSim\Provisioning\Slug;

/**
 * The name of a model that has a rule for it: the same methods whatever the model. The model gives its rule in the constant NAME:
 * `punctuation` (what a name may have besides letters, digits and the space), and `min` and `max` (characters, spaces count) and
 * `words` when it limits them.
 */
trait HasName
{
    /** The reason a name is refused, null when it is fine. An accent is not refused, it is transliterated (Noël becomes Noel). */
    public static function validateName(mixed $value): ?string
    {
        $rule = static::NAME;
        $name = trim(Slug::ascii((string) $value));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ' . preg_quote($rule['punctuation'], '/') . ']*$/', $name) !== 1) {
            return $rule['punctuation'] === ''
                ? _('invalid (letters, digits, space)')
                : sprintf(_('invalid (letters, digits, space %s)'), implode(' ', str_split($rule['punctuation'])));
        }

        return match (true) {
            isset($rule['min']) && mb_strlen($name) < $rule['min'] => sprintf(_('too short (%d min)'), $rule['min']),
            isset($rule['max']) && mb_strlen($name) > $rule['max'] => sprintf(_('too long (%d max)'), $rule['max']),
            isset($rule['words']) && count(preg_split('/ +/', $name) ?: []) > $rule['words'] => sprintf(
                _('too many words (%d max)'),
                $rule['words'],
            ),
            default => null,
        };
    }

    /** The name in Latin letters without accents (the viewers lose the accents), every alphabet transliterated. */
    public static function normalizeName(string $name): string
    {
        return Slug::latin($name, static::NAME['punctuation']);
    }
}
