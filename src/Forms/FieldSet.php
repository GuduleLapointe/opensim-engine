<?php

declare(strict_types=1);

namespace OpenSim\Forms;

use OpenSim\Result;

/**
 * A set of field definitions, in the standard format every form of the project reads, whoever shows it (the terminal, a web
 * page, WordPress, Laravel): a list of
 *
 *   name         the key of the answer (required)
 *   label        what is shown
 *   type         string, integer, boolean, secret, select (checkbox, radio... for the pages), as OpenSim_Field has them
 *   default      the value proposed, when it does not depend on other answers
 *   description  what helps to answer
 *   options      value => label, for select
 *   required     true when an answer is needed
 *   callback     checks the value: callable(mixed $value, array $answers, array $field): ?string, a message when it is wrong
 *                (`validation` is the same, as OpenSim_Field names it)
 *   normalize    the value in the form the model wants: callable(string $value): string. The callback checks that form; a value that
 *                changes is a correction the person confirms (see corrections()), told as "transliterated" when nothing else is wrong
 *   condition    [field => value or list of values]: shown only when every one of these answers has one of these values
 *   follows      [field, ...]: the default of this field is worked out from these ones, and follows them while the field is not
 *                changed (a form keeps only what the person changed or confirmed of the others)
 *   enable       false hides the field (the model knows it makes no sense here)
 *   group        which screen of a wizard it is on
 *   access       [view => role, edit => role]: who sees and changes it (the roles are the project's, the framework maps them)
 *
 * Nothing here shows anything or reads a request: it says which fields are visible for some answers and what is wrong with them.
 */
final class FieldSet
{
    /** @var array<string,array<string,mixed>> */
    private array $fields = [];

    /** @param list<array<string,mixed>> $fields */
    public function __construct(array $fields)
    {
        foreach ($fields as $field) {
            if (!isset($field['name']) || !is_string($field['name']) || $field['name'] === '') {
                throw new \InvalidArgumentException('A field has a name');
            }
            $this->fields[$field['name']] = $field;
        }
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_values($this->fields);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->fields);
    }

    /** @return ?array<string,mixed> */
    public function get(string $name): ?array
    {
        return $this->fields[$name] ?? null;
    }

    /** The fields of one screen. */
    public function group(string $group): self
    {
        return new self(
            array_values(array_filter($this->fields, static fn(array $f): bool => ($f['group'] ?? '') === $group)),
        );
    }

    /** Whether a field is shown, for these answers. */
    public function visible(string $name, array $answers): bool
    {
        $field = $this->fields[$name] ?? null;
        if ($field === null || ($field['enable'] ?? true) === false) {
            return false;
        }
        foreach ($field['condition'] ?? [] as $other => $values) {
            $answer = $answers[$other] ?? null;
            $matches = false;
            foreach ((array) $values as $value) {
                $matches = $matches || self::same($answer, $value);
            }
            if (!$matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * What is wrong with some answers, only for the fields that are shown.
     *
     * @param array<string,mixed> $answers all of them, the ones that are not given count as empty
     * @return array<string,string> name => message
     */
    public function problems(array $answers): array
    {
        $problems = [];
        foreach ($this->fields as $name => $field) {
            if (!$this->visible($name, $answers)) {
                continue;
            }
            $value = $answers[$name] ?? null;
            $empty = $value === null || (is_string($value) && trim($value) === '');
            if ($empty) {
                if (($field['required'] ?? false) === true) {
                    $problems[$name] = _('required');
                }

                continue;
            }
            if (($field['type'] ?? '') === 'boolean' && !Result::isTrue($value) && !Result::isFalse($value)) {
                $problems[$name] = _('invalid');

                continue;
            }
            $checked = self::normalized($field, $value);
            $callback = $field['callback'] ?? ($field['validation'] ?? null);
            if (is_callable($callback) && ($message = $callback($checked, $answers, $field)) !== null) {
                $problems[$name] = (string) $message;
            } elseif (is_string($value) && $checked !== trim($value)) {
                $problems[$name] = _('transliterated');
            }
        }

        return $problems;
    }

    /**
     * The values the model corrects, by field: the ones that are not in the form it wants. The form shows them, and what comes back
     * is the corrected value (the person confirms it by sending it again).
     *
     * @param array<string,mixed> $answers
     * @return array<string,string> name => corrected value
     */
    public function corrections(array $answers): array
    {
        $corrections = [];
        foreach ($this->fields as $name => $field) {
            $value = $answers[$name] ?? null;
            if (is_string($value) && trim($value) !== '' && ($normalized = self::normalized($field, $value)) !== trim($value)) {
                $corrections[$name] = $normalized;
            }
        }

        return $corrections;
    }

    /** A value in the form its field wants, the value itself when the field does not say. */
    private static function normalized(array $field, mixed $value): mixed
    {
        $normalize = $field['normalize'] ?? null;

        return is_callable($normalize) && is_string($value) ? $normalize(trim($value)) : $value;
    }

    /** Whether two values are the same answer: a yes is true, a number written is the number. */
    private static function same(mixed $answer, mixed $value): bool
    {
        if (is_bool($answer) || is_bool($value)) {
            return Result::isTrue($answer) === Result::isTrue($value);
        }

        return (string) $answer === (string) $value;
    }
}
