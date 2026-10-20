<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Config\IniEditor;
use OpenSim\Config\TextFileService;

/**
 * The options of a grid that are ini files: each is a file of the resources of the engine (resources/options/<name>.ini) combined into a
 * file of the grid, so that adding or changing an option is writing a file, never changing the code that installs. The file says which
 * one in its first lines (`;; target: config-include/osslEnable.ini`, relative to the folder of the grid); the plan says which are on.
 */
final class GridOptions
{
    /** The ini file of an option. */
    public static function file(string $name): string
    {
        return dirname(__DIR__, 2) . "/resources/options/$name.ini";
    }

    /** The file of the grid an option is combined into, as the template says it; null when it does not. */
    public static function target(string $name): ?string
    {
        $text = is_file(self::file($name)) ? TextFileService::read(self::file($name)) : '';

        return preg_match('/^;+\s*target:\s*(\S+)/mi', $text, $m) === 1 ? $m[1] : null;
    }

    /**
     * The options of a plan that are on and are ini files: the names of their templates.
     *
     * @return list<string>
     */
    public static function enabled(GridPlan $plan): array
    {
        return array_keys(array_filter(['ossl-extended' => $plan->ossl]));
    }

    /**
     * Combine an option into a file of the grid: the settings it has take the place of the ones of the file.
     *
     * @return ?string the path when written, null when there was nothing to change
     */
    public static function combine(string $name, string $target): ?string
    {
        $source = self::file($name);
        if (!is_file($source) || !is_file($target)) {
            return null;
        }
        $ini = IniEditor::load($target);
        $before = $ini->toString();
        $ini->overlay(TextFileService::read($source));
        if ($ini->toString() === $before) {
            return null;
        }
        $ini->save($target);

        return $target;
    }
}
