<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * The log file of an instance, read from the point where a start began: what an earlier run wrote is not about this one.
 */
final class LogFile
{
    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    /** Where the file ends now (a byte offset), to read only what comes after. */
    public function mark(): int
    {
        clearstatcache(true, $this->path);
        $size = is_file($this->path) ? filesize($this->path) : 0;

        return $size === false ? 0 : $size;
    }

    /** What was written after a mark. */
    public function since(int $mark): string
    {
        if (!is_readable($this->path)) {
            return '';
        }
        clearstatcache(true, $this->path);
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            return '';
        }
        // A file that is shorter than the mark was replaced: it is read from its start
        fseek($handle, $mark <= (int) filesize($this->path) ? $mark : 0);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    /** The last line of the log without its date and level, null when there is none. */
    public function lastLine(): ?string
    {
        if (!is_readable($this->path)) {
            return null;
        }
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            return null;
        }
        $size = (int) filesize($this->path);
        fseek($handle, max(0, $size - 4096));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);
        $lines = array_values(
            array_filter(explode("\n", str_replace("\r", '', $tail)), static fn(string $l): bool => trim($l) !== ''),
        );
        if ($lines === []) {
            return null;
        }

        return (string) preg_replace('/^[0-9-]+ [0-9:,.]+ +[A-Z]+ +/', '', (string) end($lines));
    }
}
