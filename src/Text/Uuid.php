<?php

namespace OpenSim\Text;

/**
 * UUIDs.
 */
final class Uuid
{
    /** The keys OpenSimulator has for nobody: all zeros (the global `NULL_KEY`), and zeros ending with a one. */
    public const NULL_KEYS = ['00000000-0000-0000-0000-000000000000', '00000000-0000-0000-0000-000000000001'];

    /** Whether it is one of the keys for nobody. */
    public static function isNullKey(mixed $uuid): bool
    {
        return is_string($uuid) && in_array(strtolower(trim($uuid)), self::NULL_KEYS, true);
    }

    /** Whether it is nothing: what PHP calls empty (null, '', 0, false, []), or a key for nobody. */
    public static function isEmpty(mixed $value): bool
    {
        return empty($value) || self::isNullKey($value);
    }

    /**
     * Verify if given string is an UUID.
     * In theory, we would check want v4-compliant uuids
     * (xxxxxxxx-xxxx-4xxx-[89AB]xxx-xxxxxxxxxxxx) but OpenSimulator seems to have
     * lot of non v4-compliant uuids left, so stict defaults to false.
     *
     * @param  [type]  $uuid                 string to verify
     * @param  boolean $nullok               accept null value or null key as valid (default false)
     * @param  boolean $strict               apply strict UUID v4 implentation (default false)
     * @return boolean
     */
    public static function isValid($uuid, $nullok = false, $strict = false): bool
    {
        // Nothing is the null key's case, accepted when asked; anything else that is not a string is not a UUID
        if ($uuid === null || $uuid === '') {
            return $nullok;
        }
        if (!is_string($uuid)) {
            return false;
        }
        if (self::isNullKey($uuid)) {
            return $nullok;
        }

        if ($strict) {
            return (bool) preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i', $uuid);
        } else {
            return (bool) preg_match(
                '/^[0-9A-F]{8,8}-[0-9A-F]{4,4}-[0-9A-F]{4,4}-[0-9A-F]{4,4}-[0-9A-F]{12,12}$/i',
                $uuid,
            );
        }
    }
}
