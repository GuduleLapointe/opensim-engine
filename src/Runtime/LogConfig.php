<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * The log4net config of an instance (`Robust.exe.config`, `<name>.LogConfig`): the file its appender writes.
 */
final class LogConfig
{
    /** The log file of the file appender of a log config, null when there is none. */
    public static function logFile(string $config): ?string
    {
        $text = is_file($config) ? (string) file_get_contents($config) : '';
        if (preg_match('/<appender[^>]*LogFileAppender.*?<file\s+value="([^"]+)"/s', $text, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * A log config made from the model of the core, its appenders pointed at the log files of the instance.
     *
     * @param string $model the config of the core (`bin/Robust.exe.config`)
     * @param string $base the base name of the assembly (Robust, OpenSim)
     */
    public static function render(string $model, string $base, string $logsDirectory, string $instance): string
    {
        $text = (string) file_get_contents($model);
        $text = preg_replace('#[a-zA-Z0-9./_-]*/*' . preg_quote($base, '#') . '\.log#', $logsDirectory . "/$instance.log", $text) ?? $text;

        return preg_replace('#[a-zA-Z0-9./_-]*/*' . preg_quote($base, '#') . 'Stats\.log#', $logsDirectory . "/$instance.Stats.log", $text) ?? $text;
    }
}
