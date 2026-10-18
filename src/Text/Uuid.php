<?php

namespace OpenSim\Text;

/**
 * UUIDs.
 */
final class Uuid
{
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
        if (defined('NULL_KEY') && $uuid == NULL_KEY) {
            return $nullok;
        }

        if ($strict) {
            return (bool) preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i', $uuid);
        } else {
            return (bool) preg_match('/^[0-9A-F]{8,8}-[0-9A-F]{4,4}-[0-9A-F]{4,4}-[0-9A-F]{4,4}-[0-9A-F]{12,12}$/i', $uuid);
        }
    }
}
