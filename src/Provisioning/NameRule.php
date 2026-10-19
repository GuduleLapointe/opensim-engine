<?php

namespace OpenSim\Provisioning;

/**
 * The rule of a name made of Latin letters, digits, spaces and some punctuation. The model of the thing says the punctuation and the
 * length; the check, the message and the correction come from here, so that a rule is written once.
 */
final class NameRule
{
    /**
     * @param string $punctuation what a name may have besides letters, digits and the space
     * @param ?int $length the longest name, no limit when null
     */
    public function __construct(
        private readonly string $punctuation,
        private readonly ?int $length = null,
    ) {}

    /** The reason a name is refused, null when it is fine. */
    public function problem(string $name): ?string
    {
        $pattern =
            '/^[A-Za-z0-9][A-Za-z0-9 ' .
            preg_quote($this->punctuation, '/') .
            ']' .
            ($this->length === null ? '*' : '{0,' . ($this->length - 1) . '}') .
            '$/';

        return preg_match($pattern, trim($name)) === 1
            ? null
            : sprintf(_('invalid (letters, digits, space %s)'), implode(' ', str_split($this->punctuation)));
    }

    /** The name in Latin letters without accents (the viewers lose the accents), every alphabet transliterated. */
    public function normalize(string $name): string
    {
        return Slug::latin($name, $this->punctuation);
    }
}
