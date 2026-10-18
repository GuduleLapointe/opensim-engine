<?php

namespace OpenSim\Provisioning;

use OpenSim\Runtime\Environment;

/**
 * The instances that have to restart to take into account what the setup did, and why: a text
 * file, one `<instance> <reason>` per line, kept until they restart (the setup offers it when it
 * ends). Written by the process that writes the files of the install, so the file is theirs.
 */
final class PendingRestarts
{
    public static function path(Environment $environment): string
    {
        $root = $environment->layout->dataRoot();

        return $root === '' ? '' : "$root/pending-restarts.txt";
    }

    public static function add(Environment $environment, string $instance, string $reason): void
    {
        $path = self::path($environment);
        $line = "$instance " . trim((string) preg_replace('/\s+/', ' ', $reason));
        if ($path === '' || in_array($line, self::lines($path), true)) {
            return;
        }
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0o755, true);
        }
        @file_put_contents($path, "$line\n", FILE_APPEND);
    }

    /** @return list<array{instance:string,reason:string}> */
    public static function read(Environment $environment): array
    {
        $entries = [];
        foreach (self::lines(self::path($environment)) as $line) {
            [$instance, $reason] = array_pad(explode(' ', $line, 2), 2, '');
            $entries[] = ['instance' => $instance, 'reason' => $reason];
        }

        return $entries;
    }

    public static function clear(Environment $environment): void
    {
        $path = self::path($environment);
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /** @return list<string> */
    private static function lines(string $path): array
    {
        if ($path === '' || !is_file($path)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: [])));
    }
}
