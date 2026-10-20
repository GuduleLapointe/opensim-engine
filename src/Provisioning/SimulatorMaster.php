<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\IniEditor;
use OpenSim\Config\InstanceSettings;
use OpenSim\Config\TextFileService;
use OpenSim\Models\Grid;

/**
 * The master config of the simulators of a grid, `OpenSimGridDefaults.ini` in its folder, given to OpenSimulator with
 * `-inimaster`: it replaces the OpenSimDefaults.ini of the core and holds, in the order they are laid:
 *
 *  1. the defaults of the core;
 *  2. the model of the toolset (resources/OpenSimKitDefaults.ini): the folders OpenSimulator would take in the core, which is
 *     read-only, and the storage;
 *  3. what the grid is: its folders, its address and ports (those of its Robust), its architecture and its OSSL;
 *  4. what the grid decided: the settings its OpenSim.ini adds to the one of the core (the search, the offline messages, the
 *     economy, the profiles... see GridOptions), which the helpers read in the same file.
 *
 * A grid joined by a preset (a grid that runs elsewhere, OSGrid...) has the same: its folder is `<etc>/grids/<nick>` for the
 * description of the grid and its simulators, and the folder of its preset (`<etc>/presets/<name>`, the structure of a grid's folder)
 * holds what the grid is and decides: its architecture, its OSSL and its OpenSim.ini, which the administrator edits there for all
 * its simulators.
 *
 * The config of a simulator then holds what makes it its own (see SimulatorConfig). The master is generated, never edited: it is made
 * again, when it differs, each time a simulator starts. A grid with no master keeps the config of its simulators whole.
 */
final class SimulatorMaster
{
    public const FILE = 'OpenSimGridDefaults.ini';

    /** The master of the grid whose folder is given. */
    public static function path(string $gridDir): string
    {
        return rtrim($gridDir, '/') . '/' . self::FILE;
    }

    /** Whether the simulators of the grid have a master. */
    public static function exists(string $gridDir): bool
    {
        return is_file(self::path($gridDir));
    }

    /**
     * Make the master when it differs from the one there is.
     *
     * @param string $binDir the bin folder of the core the grid runs with
     * @return ?string the path when written
     */
    public function ensure(string $gridDir, string $binDir): ?string
    {
        $path = self::path($gridDir);
        $text = $this->render($gridDir, $binDir);
        if (is_file($path) && TextFileService::read($path) === $text) {
            return null;
        }
        file_put_contents($path, $text);

        return $path;
    }

    /** The text of the master. */
    public function render(string $gridDir, string $binDir): string
    {
        $gridDir = rtrim($gridDir, '/');
        $configDir = self::presetDir($gridDir) ?? $gridDir;
        $option = (new Presets([], dirname($gridDir, 2)))->options()['master'] ?? throw new \RuntimeException('The preset master is not there.');
        $ini = IniEditor::load(self::distribution($binDir, 'OpenSimDefaults.ini') ?? '');
        $ini->overlay($option->text('model.ini', []));
        $ini->overlay($this->grid($gridDir, $binDir, $configDir, $option));
        $ini->overlay($this->decisions($configDir, $binDir));

        return $option->text('header.txt', []) . $ini->toString();
    }

    /**
     * What the grid is, as the master says it: the constants every simulator uses, and the architecture of the grid.
     */
    private function grid(string $gridDir, string $binDir, string $configDir, OptionTemplate $option): string
    {
        $robust = is_file("$gridDir/Robust.HG.ini") ? "$gridDir/Robust.HG.ini" : "$gridDir/Robust.ini";
        $info = is_file($robust) ? InstanceSettings::of($robust) : [];
        $conf = ConfigReader::file("$gridDir/" . basename($gridDir) . '.conf')['Grid'] ?? [];
        $host = (string) ($info['baseHostname'] ?? ($conf['BaseHostname'] ?? 'localhost'));
        $public = (int) ($info['publicPort'] ?? ($conf['PublicPort'] ?? 8002));
        $private = (int) ($info['privatePort'] ?? ($conf['PrivatePort'] ?? 8003));
        $hypergrid = basename($robust) === 'Robust.HG.ini' || strtolower($conf['Hypergrid'] ?? '') === 'true';
        $standalone = strtolower($conf['Standalone'] ?? '') === 'true';
        return $option->text('grid.ini', [
            'bin_dir' => $binDir,
            'base_hostname' => $host,
            'public_port' => (string) $public,
            'private_port' => (string) $private,
            'grid_dir' => $gridDir,
            'config_dir' => $configDir,
            'architecture' => ($standalone ? 'Standalone' : 'Grid') . ($hypergrid ? 'Hypergrid' : ''),
            // The services of a standalone say where its web site is, as those of Robust do
            'standalone_web_url' => $standalone ? (string) ($conf['WebUrl'] ?? '') : '',
        ]);
    }

    /**
     * The folder of the preset a grid is joined by, null for a grid that is not.
     *
     * @param string $gridDir the folder of the grid (<etc>/grids/<nick>)
     */
    public static function presetDir(string $gridDir): ?string
    {
        $conf = ConfigReader::file(rtrim($gridDir, '/') . '/' . basename($gridDir) . '.conf')['Grid'] ?? [];
        $preset = trim((string) ($conf['Preset'] ?? ''), " \t\"");
        if ($preset === '') {
            return null;
        }
        $dir = (new Presets([], dirname($gridDir, 2)))->dir($preset);

        return is_dir($dir) ? $dir : null;
    }

    /** What the OpenSim.ini of the grid adds to the one of the core: the decisions of the grid, for its simulators. */
    private function decisions(string $gridDir, string $binDir): string
    {
        $file = "$gridDir/OpenSim.ini";
        if (!is_file($file)) {
            return '';
        }
        $base = self::distribution($binDir, 'OpenSim.ini');

        return IniEditor::load($file)->differences(IniEditor::layered($base, null));
    }

    /** A file of the core, as it is or as an example. */
    private static function distribution(string $binDir, string $file): ?string
    {
        foreach (["$binDir/$file", "$binDir/$file.example"] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
