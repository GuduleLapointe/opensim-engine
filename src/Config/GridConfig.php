<?php

declare(strict_types=1);

namespace OpenSim\Config;

/**
 * The configuration of an OpenSimulator environment, as a nested array that follows the structure of the ini files: whichever the
 * application that asks (the helpers, a WordPress plugin, a command), it finds a setting where OpenSimulator has it.
 *
 *     $config->get('Robust.GridInfoService.SearchURL')     // a setting of a file
 *     $config->find('GridInfoService', 'SearchURL')        // the first file that says it
 *     $config->section('GridInfoService')                  // all its settings, whatever the files
 *     $config->tree()['Robust']['GridInfoService']         // the array itself
 *
 * The files are read by ConfigReader, the way the OpenSimulator executables read them. Nothing is converted here (constants,
 * variables, defaults of an application are made by the application, at the last moment), nothing is created, nothing is written.
 *
 * The files of a grid folder (`/etc/opensim/grids/<grid>/` with the kit) are the roles of the tree, in the order of their
 * authority: Robust (Robust.HG.ini or Robust.ini), OpenSim (OpenSim.ini), sims (sims/<name>.ini, one entry each),
 * MoneyServer (MoneyServer.ini), then the extra files an application names (the helpers have Helpers.ini).
 */
class GridConfig
{
    /** The files that hold the Robust config, the first that exists is the one. */
    public const ROBUST_FILES = ['Robust.HG.ini', 'Robust.ini'];

    /**
     * @param array<string,mixed> $tree role => section => key => value, in the order of authority
     * @param array<string,string> $files role => path
     */
    public function __construct(private array $tree = [], private array $files = [])
    {
    }

    /**
     * The config of a grid folder: the files it has, each with the files it includes. The constants of Robust serve the files that
     * follow (the web address). A file that is not there is not a role.
     *
     * @param string $dir the folder of the grid
     * @param array<string,string> $extra role => file name, files of an application, read last (['Helpers' => 'Helpers.ini'])
     */
    public static function fromFolder(string $dir, array $extra = []): static
    {
        $dir = rtrim($dir, '/');
        $tree = [];
        $files = [];
        $constants = [];
        $add = static function (string $role, string $path) use (&$tree, &$files, &$constants): void {
            $ini = ConfigReader::load($path, $constants);
            if ($role === 'Robust') {
                $constants = $ini['Const'] ?? [];
            }
            $files[$role] = $path;
            if (str_starts_with($role, 'sims/')) {
                $tree['sims'][substr($role, 5)] = $ini;
            } else {
                $tree[$role] = $ini;
            }
        };
        foreach (self::ROBUST_FILES as $name) {
            if (is_file("$dir/$name")) {
                $add('Robust', "$dir/$name");

                break;
            }
        }
        if (is_file("$dir/OpenSim.ini")) {
            $add('OpenSim', "$dir/OpenSim.ini");
        }
        // The simulators: each reads its own file, and the files it includes
        $sims = glob("$dir/sims/*.ini") ?: [];
        sort($sims);
        foreach ($sims as $path) {
            $add('sims/' . basename($path, '.ini'), $path);
        }
        // The MoneyServer reads only its own file, beside Robust.ini and OpenSim.ini
        if (is_file("$dir/MoneyServer.ini")) {
            $add('MoneyServer', "$dir/MoneyServer.ini");
        }
        foreach ($extra as $role => $name) {
            if (is_file("$dir/$name")) {
                $add($role, "$dir/$name");
            }
        }

        return new static($tree, $files);
    }

    /**
     * The whole config, as an array that follows the structure of the ini files.
     *
     * @return array<string,mixed> role => section => key => value (sims => name => section => key => value)
     */
    public function tree(): array
    {
        return $this->tree;
    }

    /**
     * A part of the tree, by its path: `Robust.GridInfoService.SearchURL` is a setting, `Robust.GridInfoService` all the settings of
     * a section, `Robust` a whole file.
     *
     * @param mixed $default what is given when there is nothing at this path
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->tree;
        foreach (explode('.', $path) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * Whether a role (a file of the environment) is part of the config.
     *
     * @param string $role Robust, OpenSim, MoneyServer, sims/<name>, an extra file
     */
    public function has(string $role): bool
    {
        return str_starts_with($role, 'sims/') ? isset($this->tree['sims'][substr($role, 5)]) : isset($this->tree[$role]);
    }

    /** The file a role was read from, null for a role that is not made of a file. */
    public function file(string $role): ?string
    {
        return $this->files[$role] ?? null;
    }

    /**
     * The files read, in the order of their authority.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return array_values($this->files);
    }

    /** The value of a setting: the first file that says it (an empty value is not saying it). */
    public function find(string $section, string $key): ?string
    {
        foreach ($this->layers() as $ini) {
            if (isset($ini[$section][$key]) && $ini[$section][$key] !== '') {
                return $ini[$section][$key];
            }
        }

        return null;
    }

    /**
     * Every setting of a section, whatever the file: the first file that has a key gives its value.
     *
     * @return array<string,string> empty when no file has such a section
     */
    public function section(string $name): array
    {
        $section = [];
        foreach ($this->layers() as $ini) {
            $section += $ini[$name] ?? [];
        }

        return $section;
    }

    /**
     * Every section of the config, whatever the file: the first file that has a key gives its value.
     *
     * @return array<string,array<string,string>> section => key => value
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->layers() as $ini) {
            foreach ($ini as $section => $values) {
                $all[$section] = ($all[$section] ?? []) + $values;
            }
        }

        return $all;
    }

    /**
     * The settings of a section whose key matches (DATA_SRV_<Grid> of [DataSnapshot]), with their keys.
     *
     * @param string $pattern a regular expression for the keys
     * @return array<string,string> key => value
     */
    public function keys(string $section, string $pattern): array
    {
        $found = [];
        foreach ($this->section($section) as $key => $value) {
            if ($value !== '' && preg_match($pattern, $key)) {
                $found[$key] = $value;
            }
        }

        return $found;
    }

    /**
     * The files in the order of their authority, as arrays: Robust, OpenSim, each simulator, the rest.
     *
     * @return list<array<string,array<string,string>>>
     */
    private function layers(): array
    {
        $layers = [];
        foreach ($this->tree as $role => $ini) {
            if ($role === 'sims') {
                array_push($layers, ...array_values($ini));
            } else {
                $layers[] = $ini;
            }
        }

        return $layers;
    }
}
