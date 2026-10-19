<?php

declare(strict_types=1);

namespace OpenSim\Support;

/**
 * The values OpenSimulator packs in a number: the flags of a region, of a parcel, of an item, the wants and the skills of a profile. A
 * bit is a value of a power of two; what it means is told by a list.
 */
final class Bitmask
{
    /**
     * The names of the flags a number has, in the order of the list.
     *
     * @param array<int,string> $names flag => name
     * @return list<string>
     */
    public static function names(int $mask, array $names): array
    {
        $found = [];
        foreach ($names as $flag => $name) {
            self::has($mask, $flag) && ($found[] = $name);
        }

        return $found;
    }

    /**
     * The labels of the bits a number has: the bit 0 is the first label, the bit 1 the second...
     *
     * @param list<string> $labels
     * @return list<string>
     */
    public static function labels(int $mask, array $labels): array
    {
        $found = [];
        foreach (array_values($labels) as $bit => $label) {
            ($mask & (1 << $bit)) !== 0 && ($found[] = $label);
        }

        return $found;
    }

    /** Whether the number has all the bits of a flag. */
    public static function has(int $mask, int $flag): bool
    {
        return $flag !== 0 && ($mask & $flag) === $flag;
    }

    public static function with(int $mask, int $flag): int
    {
        return $mask | $flag;
    }

    public static function without(int $mask, int $flag): int
    {
        return $mask & ~$flag;
    }
}
