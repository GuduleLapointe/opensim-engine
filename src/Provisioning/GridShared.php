<?php

namespace OpenSim\Provisioning;

use OpenSim\Config\GridFileService;
use OpenSim\Config\IniEditor;
use OpenSim\Config\TextFileService;

/**
 * The architecture files a grid shares between its simulators (its
 * config-include folder), made usable by simulators run from a read-only core.
 *
 * A simulator reads one config file and the files it includes after it, each
 * one overriding what came before, and the included paths are relative to the
 * core (where the files are only examples). So, in the copies of the grid:
 *  - the includes are absolute, pointing at the copies;
 *  - the SQLite storage included by default, which would override the database
 *    of the simulator, is off;
 *  - the asset cache goes to the cache folder of the simulator, not the core.
 *
 * Idempotent: what is already done is left as it is, so it also repairs the
 * copies of a grid made before, and keeps the changes made by hand.
 */
final class GridShared
{
    /** @param ?string $dataDirectory the data folder of the grid, where its assets and its library are; null: its own folder */
    public function __construct(
        private readonly ?string $dataDirectory = null,
        private readonly string $databaseDriver = 'mysql',
    ) {}

    /**
     * The files of the folder of a grid (and of a preset) that come from the distribution, relative to the folder: the main
     * config and its defaults, and the architecture files of config-include.
     *
     * @return list<string>
     */
    public static function files(bool $hypergrid, bool $standalone = false): array
    {
        // A standalone has the architecture and the common settings of a standalone, where a grid has those of a grid
        $architecture = ($standalone ? 'Standalone' : 'Grid') . ($hypergrid ? 'Hypergrid' : '');

        return [
            'OpenSimDefaults.ini',
            'OpenSim.ini',
            "config-include/$architecture.ini",
            'config-include/' . ($standalone ? 'StandaloneCommon.ini' : 'GridCommon.ini'),
            'config-include/FlotsamCache.ini',
            'config-include/osslDefaultEnable.ini',
            'config-include/osslEnable.ini',
            ...($standalone ? ['config-include/storage/SQLiteStandalone.ini'] : []),
        ];
    }

    /**
     * Make the folder of a grid, or of a preset, whole: every file of the distribution (the core) merged with what the folder
     * has and with the same file of the folders in $over (the preset of the kit), the same way every time, so a setting new in
     * the distribution is always there and what was chosen stays, then our own corrections (patch()), the files kept apart as
     * the distribution has them (an include stays an include). A file is written when what it would be differs from what it is.
     *
     * @param  list<string> $over folders whose files are laid over the distribution, the last winning
     * @param  list<string> $extra files besides those of files(), relative paths (the ini files a preset has)
     * @return list<string> the files written
     */
    public function merge(
        string $dir,
        string $binDir,
        bool $hypergrid,
        array $over = [],
        array $extra = [],
        bool $standalone = false,
    ): array {
        $written = [];
        foreach (array_values(array_unique(array_merge(self::files($hypergrid, $standalone), $extra))) as $file) {
            $dest = "$dir/$file";
            $source = $this->source($binDir, $file);
            $layers = array_values(array_filter($over, static fn(string $root): bool => is_file("$root/$file")));
            if ($source === null && $layers === []) {
                is_file($dest) && TextFileService::clean($dest) && ($written[] = $dest);

                continue;
            }
            // The defaults of the application are its own: a copy, consulted and never changed
            if ($file === 'OpenSimDefaults.ini' && $source !== null) {
                $text = TextFileService::read($source);
                if (!is_file($dest) || TextFileService::read($dest) !== $text) {
                    is_dir(dirname($dest)) || mkdir(dirname($dest), 0o755, true);
                    file_put_contents($dest, $text);
                    $written[] = $dest;
                }

                continue;
            }
            $ini = IniEditor::layered($source, is_file($dest) ? $dest : null);
            foreach ($layers as $root) {
                $ini->overlay(TextFileService::read("$root/$file"));
            }
            $text = $this->patched($ini->toString(), $dir, $file);
            if (is_file($dest)) {
                if (TextFileService::read($dest) === $text) {
                    continue;
                }
                @copy($dest, "$dest~");
            }
            is_dir(dirname($dest)) || mkdir(dirname($dest), 0o755, true);
            file_put_contents($dest, $text);
            $written[] = $dest;
        }

        return $written;
    }

    /**
     * Our corrections of a file of a folder, in memory: what the distribution assumes (paths relative to the core, which is
     * read-only, a storage of its own) made what the folder needs. They are the settings of the preset `config-include`.
     */
    private function patched(string $text, string $dir, string $file): string
    {
        $option = (new Presets())->options()['config-include'] ?? null;
        $ini = new IniEditor($text);
        if ($option !== null && in_array($file, $option->files(), true)) {
            // The assets and the library of the grid are read from its own folder, not the core's.
            $own = $this->dataDirectory ?? $dir;
            GridOptions::layText($ini, $option, $file, [
                'include_directory' => "$dir/config-include",
                'assets_file' => is_file("$own/assets/AssetSets.xml") ? "$own/assets/AssetSets.xml" : '',
                'library_file' => is_file("$own/inventory/Libraries.xml") ? "$own/inventory/Libraries.xml" : '',
            ]);
        }
        if ($this->databaseDriver === 'sqlite' && $file === 'config-include/StandaloneCommon.ini') {
            $ini->set('DatabaseService', 'Include-Storage', '"' . $dir . '/config-include/storage/SQLiteStandalone.ini"');
        }
        if ($this->databaseDriver === 'sqlite' && $file === 'config-include/storage/SQLiteStandalone.ini') {
            foreach (
                [
                    'DatabaseService' => 'OpenSim.db',
                    'AssetService' => 'Asset.db',
                    'HGAssetService' => 'Asset.db',
                    'InventoryService' => 'inventory.db',
                    'AvatarService' => 'avatars.db',
                    'AuthenticationService' => 'auth.db',
                    'UserAccountService' => 'userprofiles.db',
                    'GridUserService' => 'griduser.db',
                    'FriendsService' => 'friends.db',
                ] as $section => $database
            ) {
                $ini->set(
                    $section,
                    'ConnectionString',
                    '"URI=file:${Const|DataDirectory}/' . $database . ',version=3,UseUTF16Encoding=True"',
                );
            }
        }

        return $ini->toString();
    }

    /**
     * Copy what is missing from the core and patch it.
     *
     * @return list<string> the files written
     */
    public function prepare(string $gridDir, string $binDir, bool $hypergrid, bool $standalone = false): array
    {
        $dir = "$gridDir/config-include";
        $written = [];

        foreach (
            array_map(
                static fn(string $file): string => substr($file, strlen('config-include/')),
                array_values(
                    array_filter(
                        self::files($hypergrid, $standalone),
                        static fn(string $file): bool => str_starts_with($file, 'config-include/'),
                    ),
                ),
            )
            as $file
        ) {
            $dest = "$dir/$file";
            if (is_file($dest)) {
                if (TextFileService::clean($dest)) {
                    $written[] = $dest;
                }
            } else {
                $source = $this->source($binDir, "config-include/$file");
                if ($source === null) {
                    continue;
                }
                is_dir($dir) || mkdir($dir, 0o755, true);
                if (TextFileService::copy($source, $dest)) {
                    $written[] = $dest;
                }
            }
        }

        // The same corrections as merge(), for the files that are only copied
        foreach (self::files($hypergrid, $standalone) as $file) {
            if (!str_starts_with($file, 'config-include/') || !is_file("$gridDir/$file")) {
                continue;
            }
            $written[] = $this->patch("$gridDir/$file", $gridDir, $file);
        }
        $written = array_values(array_filter($written));

        return array_values(array_unique($written));
    }

    /** The file in the core (relative to its bin folder), as it is or as an example. */
    private function source(string $binDir, string $file): ?string
    {
        foreach (["$binDir/$file", "$binDir/$file.example"] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Apply our corrections to a file, written only when something changed.
     *
     * @return ?string the path when written
     */
    private function patch(string $path, string $dir, string $file): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $before = GridFileService::read($path);
        $after = $this->patched($before, $dir, $file);
        if ($after === $before) {
            return null;
        }
        file_put_contents($path, $after);

        return $path;
    }
}
