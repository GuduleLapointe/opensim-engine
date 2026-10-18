<?php

namespace OpenSim\Text;

use Throwable;

/**
 * Formatting that does not fail.
 */
final class Format
{
    /**
     * Safe sprintf function to avoid fatal errors
     * Returns unchanged format string if error occurs
     *
     * @param string $format
     * @param mixed ...$args
     * @return string
     */
    public static function sprintfSafe($format, ...$args)
    {
        try {
            return sprintf($format, ...$args);
        } catch (Throwable $e) {
            error_log("Error sprintf_safe( $format, " . join(', ', $args) . '): ' . $e->getMessage());

            return $format;
        }
    }
}
