<?php

declare(strict_types=1);

namespace OpenSim\Runtime;


/**
 * Enable the modules of the install for an instance: their files linked in the addins folder of its registry, where OpenSim
 * loads add-ins from, so that a core kept read-only needs nothing written in its bin folder. The modules to enable are the
 * ones the install names, looked for under the name of the core folder or its version. What was linked for a module no longer
 * enabled is removed.
 */
final class ModuleLinker
{
    /**
     * @param list<string> $modules the modules enabled
     * @param string $root where the modules are, by core
     * @return list<string> what was done, one line each (for the debug)
     */
    public static function sync(string $addins, Core $core, array $modules, string $root): array
    {
        $notes = [];
        $wanted = [];
        $version = preg_match('/\d+(?:\.\d+)+/', $core->name(), $m) ? $m[0] : '';

        foreach ($modules as $module) {
            $directory = '';
            foreach (array_filter([$core->name(), $version]) as $name) {
                if (is_dir("$root/$name/$module")) {
                    $directory = "$root/$name/$module";

                    break;
                }
            }
            if ($directory === '') {
                $notes[] = "module $module: not installed for {$core->name()}, skipped";

                continue;
            }
            if (!is_dir($addins) && !mkdir($addins, 0o755, true) && !is_dir($addins)) {
                return $notes;
            }
            foreach (glob("$directory/*") ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                $link = "$addins/" . basename($file);
                if (is_link($link) || is_file($link)) {
                    unlink($link);
                }
                symlink($file, $link);
                $wanted[$link] = true;
            }
            $notes[] = "module $module enabled from $directory";
        }

        // What was linked for a module that is not enabled any more
        foreach (is_dir($addins) ? (glob("$addins/*") ?: []) : [] as $file) {
            if (is_link($file) && str_starts_with((string) readlink($file), "$root/") && !isset($wanted[$file])) {
                unlink($file);
                $notes[] = 'module file ' . basename($file) . ' removed';
            }
        }

        return $notes;
    }
}
