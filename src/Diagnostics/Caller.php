<?php

namespace OpenSim\Diagnostics;

use Error;

/**
 * Where a message comes from, for the logs.
 */
final class Caller
{
    public static function details($level = 2)
    {
        // get calling function and file
        $trace = debug_backtrace();
        $level = isset($trace[$level]) ? $level : 1;
        $caller = $trace[$level] ?? $trace[0];

        $file = empty($caller['file']) ? '' : $caller['file'];
        $function = $level > 1 ? $caller['function'] . '()' ?? '' : '';
        $line = $caller['line'] ?? 0;
        $class = $caller['class'] ?? '';
        $type = $caller['type'] ?? '::';
        if ($function && $class) {
            $function = $class . $type . $function;
        }
        $file = $file . ':' . $line;
        return trim(
            sprintf(
                '%s%s %s in %s',
                $function,
                empty($caller['error_code']) ? '' : " Error {$caller['error_code']}",
                $caller['message'] ?? '',
                $file,
            ),
        );
    }

    public static function notify($message, $error_code = 0, $die = false)
    {
        // get calling function and file
        $trace = debug_backtrace();

        if (isset($trace[1])) {
            $caller = $trace[1];
        } else {
            $caller = $trace[0];
        }
        $file = empty($caller['file']) ? '' : $caller['file'];
        $function = $caller['function'] . '()' ?? 'main';
        $line = $caller['line'] ?? 0;
        $class = $caller['class'] ?? 'main';
        $type = $caller['type'] ?? '::';
        if ($class != 'main') {
            $function = $class . $type . $function;
        }
        $file = $file . ':' . $line;
        $message = sprintf(
            '%s%s: %s in %s',
            $function,
            empty($error_code) ? '' : " Error $error_code",
            $message,
            $file,
        );
        error_log($message);
        if ($die == true) {
            die($error_code);
        }
    }

    public static function notice($message)
    {
        echo $message . "\n";
    }

    public static function debug($message = '')
    {
        if (empty($message)) {
            return;
        }
        if (!is_string($message)) {
            $message = print_r($message, true);
        }
        error_log('[DEBUG] ' . $message);
        echo $message . "\n";
    }
}
