<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

/**
 * The name of an avatar as a person writes it: "First Last", "First.Last", a first name alone (its last name is Resident), and
 * "First Last@grid.example:8002" for an avatar of another grid (the hypergrid way).
 */
final class AvatarName
{
    public const DEFAULT_LAST_NAME = 'Resident';

    private function __construct(
        public readonly string $first,
        public readonly string $last,
        public readonly ?string $grid = null,
    ) {}

    /** The name as typed, null when it is not one. */
    public static function parse(string $name): ?self
    {
        $name = trim($name);
        $grid = null;
        if (str_contains($name, '@')) {
            [$name, $grid] = array_map('trim', explode('@', $name, 2));
            if ($grid === '' || preg_match('/[\s\x00-\x1f]/', $grid) === 1) {
                return null;
            }
        }
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) === 1 && substr_count($parts[0], '.') === 1) {
            $parts = explode('.', $parts[0]);
        }
        if ($parts === [] || count($parts) > 2) {
            return null;
        }
        $first = $parts[0];
        $last = $parts[1] ?? self::DEFAULT_LAST_NAME;
        foreach ([$first, $last] as $part) {
            if ($part === '' || strlen($part) > 64 || preg_match('/[\x00-\x1f]/', $part) === 1) {
                return null;
            }
        }

        return new self($first, $last, $grid);
    }

    /** "First Last". */
    public function full(): string
    {
        return "{$this->first} {$this->last}";
    }
}
