<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Config\IniEditor;
use OpenSim\Config\TextFileService;

/**
 * The options of a grid that are ini files: each is a file of the resources of the engine (resources/options/<name>.ini) combined into a
 * file of the grid, so that adding or changing an option is writing a file, never changing the code that installs.
 */
final class GridOptions
{
    /** The ini file of an option. */
    public static function file(string $name): string
    {
        return dirname(__DIR__, 2) . "/resources/options/$name.ini";
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
