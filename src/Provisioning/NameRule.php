<?php

namespace OpenSim\Provisioning;

/**
 * The rule of a name made of Latin letters, digits, spaces and some punctuation. The model of the thing says the punctuation, the
 * length and the number of words; the check, the message and the correction come from here, so that a rule is written once.
 */
final class NameRule
{
    /**
     * @param string $punctuation what a name may have besides letters, digits and the space
     * @param ?int $min the fewest characters (spaces count), no limit when null
     * @param ?int $max the most characters (spaces count), no limit when null
     * @param ?int $words the most words, no limit when null
     */
    public function __construct(
        private readonly string $punctuation = '',
        private readonly ?int $min = null,
        private readonly ?int $max = null,
        private readonly ?int $words = null,
    ) {}

    /** The reason a name is refused, null when it is fine. */
    public function problem(string $name): ?string
    {
        $name = trim($name);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9 ' . preg_quote($this->punctuation, '/') . ']*$/', $name) !== 1) {
            return $this->punctuation === ''
                ? _('invalid (letters, digits, space)')
                : sprintf(_('invalid (letters, digits, space %s)'), implode(' ', str_split($this->punctuation)));
        }

        return match (true) {
            $this->min !== null && mb_strlen($name) < $this->min => sprintf(_('too short (%d min)'), $this->min),
            $this->max !== null && mb_strlen($name) > $this->max => sprintf(_('too long (%d max)'), $this->max),
            $this->words !== null && count(preg_split('/ +/', $name) ?: []) > $this->words => sprintf(
                _('too many words (%d max)'),
                $this->words,
            ),
            default => null,
        };
    }

    /** The name in Latin letters without accents (the viewers lose the accents), every alphabet transliterated. */
    public function normalize(string $name): string
    {
        return Slug::latin($name, $this->punctuation);
    }
}
