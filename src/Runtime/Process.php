<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * A process of an instance (Robust, a simulator) running on this machine.
 */
final class Process
{
    public function __construct(
        public readonly int $pid,
        public readonly string $user,
        public readonly float $cpu,
        public readonly float $memory,
        public readonly int $rss,
        public readonly string $arguments,
    ) {
    }

    /** The config file it was started with (`-inifile=`), empty when it has none. */
    public function ini(): string
    {
        return preg_match('/ -inifile=(\S+)/', ' ' . $this->arguments, $m) ? trim($m[1], "'\"") : '';
    }

    /** The log file it writes: its `-logfile` (Robust), else the file of its log config. */
    public function logFile(): string
    {
        if (preg_match('/ -logfile=\'?([^\' ]+)\'?/', ' ' . $this->arguments, $m)) {
            return $m[1];
        }
        if (preg_match('/ -logconfig=\'?([^\' ]+)\'?/', ' ' . $this->arguments, $m)) {
            return LogConfig::logFile($m[1]) ?? '';
        }

        return '';
    }

    /** Whether it was started with this config file. */
    public function runs(string $ini): bool
    {
        return $this->ini() === $ini;
    }
}
