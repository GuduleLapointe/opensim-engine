<?php

declare(strict_types=1);

namespace OpenSim\Config;

/**
 * The one reader of the ini files of OpenSimulator: sections, `key = value`, quotes around a value optional, `;` and `#`
 * comments, `Include-<name>` keys that give other files, `${Const|Name}` references. It reads them the way the executables do:
 *
 *  - a key given twice in a section: the first is the one that counts;
 *  - the files a file includes are read after it (in any section; a relative path is relative to the file that includes it, or to
 *    the folder above, where OpenSimulator is run from; `*` is a wildcard), and what they say replaces what was said before; a
 *    file is read once; a file nothing includes is not read;
 *  - Robust also reads the `*.ini` of its `ConfigDirectory`;
 *  - `${Const|Name}` is replaced by the constant of the files, whichever file has it.
 *
 * Reading is all it does: nothing is created, nothing is written (see IniEditor for that). A file that cannot be read is empty.
 */
final class ConfigReader
{
    /**
     * The sections and keys of a text as written, with nothing replaced.
     *
     * @return array<string,array<string,string>> section => key => value
     */
    public static function parse(string $text): array
    {
        $ini = [];
        $section = '';
        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === ';' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                $section = trim($m[1]);
                $ini[$section] ??= [];

                continue;
            }
            if (!preg_match('/^([^=]+?)\s*=\s*(.*)$/', $line, $m)) {
                continue;
            }
            $value = trim($m[2]);
            if (preg_match('/^"(.*)"\s*(?:[;#].*)?$/', $value, $quoted)) {
                $value = $quoted[1];
            }
            // A key given twice in a section: the first is the one OpenSimulator keeps
            $ini[$section][trim($m[1])] ??= $value;
        }

        return $ini;
    }

    /**
     * A file as written, without the files it includes. Empty when it cannot be read.
     *
     * @return array<string,array<string,string>>
     */
    public static function file(string $path): array
    {
        $text = is_file($path) && is_readable($path) ? @file_get_contents($path) : false;

        return $text === false ? [] : self::parse($text);
    }

    /**
     * A file and the files it includes.
     *
     * @param array<string,string> $constants those of another file (Robust's), for the ones the files read have not
     * @param bool $expand replace the `${Const|Name}` references by their value, else keep the text as written (to port a config)
     * @param bool $directory read the `*.ini` of the `ConfigDirectory` of `[Startup]` too, as Robust does
     * @return array<string,array<string,string>> section => key => value, empty when unreadable
     */
    public static function load(string $path, array $constants = [], bool $expand = true, bool $directory = false): array
    {
        $merged = [];
        $queue = [$path];
        $done = [];
        while ($queue) {
            $file = array_shift($queue);
            $real = realpath($file) ?: $file;
            if (isset($done[$real])) {
                continue;
            }
            $done[$real] = true;
            $ini = self::file($file);
            foreach ($ini as $section => $values) {
                $merged[$section] = array_merge($merged[$section] ?? [], $values);
            }
            $const = ($merged['Const'] ?? []) + $constants;
            foreach ($ini as $values) {
                foreach ($values as $key => $value) {
                    if (stripos($key, 'Include-') === 0 && $value !== '') {
                        array_push($queue, ...self::included($value, dirname($file), $const));
                    }
                }
            }
            if ($directory && $file === $path) {
                $folder = self::replaceConstants($merged['Startup']['ConfigDirectory'] ?? '', $const);
                if ($folder !== '' && !str_contains($folder, '${')) {
                    $folder = str_starts_with($folder, '/') ? $folder : dirname($file) . "/$folder";
                    $found = glob(rtrim($folder, '/') . '/*.ini') ?: [];
                    sort($found);
                    array_push($queue, ...$found);
                }
            }
        }

        return $expand ? self::expand($merged, $constants) : $merged;
    }

    /**
     * Replace `${Const|Name}` by the value of Name in [Const], itself expanded.
     *
     * @param array<string,array<string,string>> $ini
     * @param array<string,string> $others the constants of another file, used for the ones this one has not
     * @return array<string,array<string,string>>
     */
    public static function expand(array $ini, array $others = []): array
    {
        $const = ($ini['Const'] ?? []) + $others;
        foreach ($const as $key => $value) {
            $const[$key] = self::replaceConstants($value, $const);
        }
        foreach ($ini as $section => $values) {
            foreach ($values as $key => $value) {
                $ini[$section][$key] = self::replaceConstants($value, $const);
            }
        }

        return $ini;
    }

    /**
     * `${Const|Name}` replaced by the value of Name in the constants, a few levels deep. The name is looked for as it is written,
     * else without the case; a constant that is not known stays as it is.
     *
     * @param array<string,string> $const
     */
    public static function replaceConstants(string $value, array $const): string
    {
        $lower = null;
        for ($i = 0; $i < 5 && str_contains($value, '${Const|'); $i++) {
            $value = (string) preg_replace_callback(
                '/\$\{Const\|([^}]+)\}/',
                static function (array $m) use ($const, &$lower): string {
                    if (isset($const[$m[1]])) {
                        return $const[$m[1]];
                    }
                    $lower ??= array_change_key_case($const, CASE_LOWER);

                    return $lower[strtolower($m[1])] ?? $m[0];
                },
                $value,
            );
        }

        return $value;
    }

    /**
     * The first value of a key in any section, whichever the case of the key, null when no section has it.
     *
     * @param array<string,array<string,string>> $ini
     */
    public static function find(array $ini, string $key): ?string
    {
        foreach ($ini as $values) {
            foreach ($values as $name => $value) {
                if (strcasecmp($name, $key) === 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * The files an `Include-` key gives.
     *
     * @param array<string,string> $const the constants known
     * @return list<string>
     */
    private static function included(string $value, string $from, array $const): array
    {
        $value = self::replaceConstants($value, $const);
        if ($value[0] === '/') {
            $candidates = [$value];
        } else {
            // From the file that includes it, else from the folder above (OpenSimulator runs from its bin folder, where
            // config-include/ is)
            $candidates = ["$from/$value", dirname($from) . "/$value"];
        }
        foreach ($candidates as $candidate) {
            if (strpbrk($candidate, '*?') === false) {
                if (is_file($candidate)) {
                    return [$candidate];
                }

                continue;
            }
            $files = glob($candidate) ?: [];
            sort($files);
            if ($files !== []) {
                return $files;
            }
        }

        return [$candidates[0]];
    }
}
