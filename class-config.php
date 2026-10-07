<?php
/**
 * The configuration of an OpenSimulator environment, as a nested array that follows the structure of the ini files: whichever
 * the application that asks (the helpers, a WordPress plugin, a command), it finds a setting where OpenSimulator has it.
 *
 *     $config->get('Robust.GridInfoService.SearchURL')     // a setting of a file
 *     $config->find('GridInfoService', 'SearchURL')        // the first file that says it
 *     $config->section('GridInfoService')                  // all its settings, whatever the files
 *     $config->tree()['Robust']['GridInfoService']         // the array itself
 *
 * A file is read the way the OpenSimulator executables read it: `Include-<name>` in any section gives other files, read after
 * it, and `${Const|Name}` is replaced by the constant of the files, whichever file has it. The first of a key given twice in a
 * section is the one that counts. Nothing is converted here (constants, variables, defaults of an application are made by the
 * application, at the last moment), nothing is created, nothing is written.
 *
 * The files of a grid folder (`/etc/opensim/grids/<grid>/` with the kit) are the roles of the tree, in the order of their
 * authority: Robust (Robust.HG.ini or Robust.ini), OpenSim (OpenSim.ini), sims (sims/<name>.ini, one entry each),
 * MoneyServer (MoneyServer.ini), then the extra files an application names (the helpers have Helpers.ini).
 *
 * @package magicoli/opensim-engine
 * @license AGPLv3
 */
class OpenSim_Config
{
    /** The files that hold the Robust config, the first that exists is the one. */
    const ROBUST_FILES = ['Robust.HG.ini', 'Robust.ini'];

    /** @var array<string,mixed> role => section => key => value, and sims => name => section => key => value */
    private $tree;

    /** @var array<string,string> role (sims/<name> for a simulator) => path of the file, for what is made of files */
    private $files;

    /**
     * @param array<string,mixed>  $tree  role => section => key => value, in the order of authority
     * @param array<string,string> $files role => path
     */
    public function __construct(array $tree = [], array $files = [])
    {
        $this->tree = $tree;
        $this->files = $files;
    }

    /**
     * The config of a grid folder: the files it has, each with the files it includes. The constants of Robust serve the files
     * that follow (the web address). A file that is not there is not a role.
     *
     * @param  string               $dir   the folder of the grid
     * @param  array<string,string> $extra role => file name, files of an application, read last (['Helpers' => 'Helpers.ini'])
     * @return self
     */
    public static function from_folder($dir, array $extra = [])
    {
        $dir = rtrim($dir, '/');
        $tree = [];
        $files = [];
        $constants = [];
        $add = function ($role, $path) use (&$tree, &$files, &$constants) {
            $ini = self::read($path, $constants);
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

        return new self($tree, $files);
    }

    /**
     * The whole config, as an array that follows the structure of the ini files.
     *
     * @return array<string,mixed> role => section => key => value (sims => name => section => key => value)
     */
    public function tree()
    {
        return $this->tree;
    }

    /**
     * A part of the tree, by its path: `Robust.GridInfoService.SearchURL` is a setting, `Robust.GridInfoService` all the settings
     * of a section, `Robust` a whole file.
     *
     * @param  string $path
     * @param  mixed  $default what is given when there is nothing at this path
     * @return mixed
     */
    public function get($path, $default = null)
    {
        $node = $this->tree;
        foreach (explode('.', (string) $path) as $part) {
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
     * @param  string $role Robust, OpenSim, MoneyServer, sims/<name>, an extra file
     * @return bool
     */
    public function has($role)
    {
        return str_starts_with($role, 'sims/') ? isset($this->tree['sims'][substr($role, 5)]) : isset($this->tree[$role]);
    }

    /**
     * The file a role was read from.
     *
     * @param  string $role
     * @return string|null null for a role that is not made of a file
     */
    public function file($role)
    {
        return $this->files[$role] ?? null;
    }

    /**
     * The files read, in the order of their authority.
     *
     * @return string[]
     */
    public function files()
    {
        return array_values($this->files);
    }

    /**
     * The value of a setting: the first file that says it (an empty value is not saying it).
     *
     * @param  string $section
     * @param  string $key
     * @return string|null
     */
    public function find($section, $key)
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
     * @param  string $name
     * @return array<string,string> empty when no file has such a section
     */
    public function section($name)
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
    public function all()
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
     * @param  string $section
     * @param  string $pattern a regular expression for the keys
     * @return array<string,string> key => value
     */
    public function keys($section, $pattern)
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
    private function layers()
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

    /**
     * Read an ini file the way OpenSimulator writes them: sections, `key = value`, quotes around a value optional, `;` and `#`
     * comments. `${Const|Name}` is replaced by the value of [Const], the one of the file first, else the one of the constants
     * given. Without the files it includes.
     *
     * @param  string               $path
     * @param  array<string,string> $constants
     * @return array<string,array<string,string>> section => key => value, empty when unreadable
     */
    public static function read_ini($path, $constants = [])
    {
        return self::expand(self::parse($path), $constants);
    }

    /**
     * A file and the files it includes, read the way the OpenSimulator executables read them: a key `Include-<name>` in any
     * section gives a file (`${Const|Name}` replaced, `*` a wildcard, a relative path being relative to the folder of the file
     * that includes it), read after the file that has it, then the ones that file includes; what a file read later says replaces
     * what was said before; a file is read once, and a file that nothing includes is not read. The constants are replaced in
     * the end, from all the files.
     *
     * @param  string               $path
     * @param  array<string,string> $constants those of another file (Robust's), for the ones the chain has not
     * @return array<string,array<string,string>> section => key => value, empty when unreadable
     */
    public static function read($path, $constants = [])
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
            $ini = self::parse($file);
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
        }

        return self::expand($merged, $constants);
    }

    /**
     * The files an `Include-` key gives.
     *
     * @param  string               $value the path as written
     * @param  string               $from  the folder of the file that includes it
     * @param  array<string,string> $const the constants known
     * @return string[]
     */
    private static function included($value, $from, $const)
    {
        $value = self::replace_constants($value, $const);
        $path = $value !== '' && $value[0] === '/' ? $value : "$from/$value";
        if (strpbrk($path, '*?') === false) {
            return [$path];
        }
        $files = glob($path) ?: [];
        sort($files);

        return $files;
    }

    /**
     * The sections and keys of an ini file as written, with nothing replaced.
     *
     * @param  string $path
     * @return array<string,array<string,string>> section => key => value, empty when unreadable
     */
    private static function parse($path)
    {
        $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;
        if ($lines === false) {
            return [];
        }

        $ini = [];
        $section = '';
        foreach ($lines as $line) {
            $line = trim(str_replace("\r", '', $line));
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

    /** `${Const|Name}` replaced by the value of Name in the constants, a few levels deep. */
    private static function replace_constants($value, $const)
    {
        for ($i = 0; $i < 5 && strpos($value, '${Const|') !== false; $i++) {
            $value = preg_replace_callback('/\$\{Const\|([^}]+)\}/', fn($m) => $const[$m[1]] ?? $m[0], $value);
        }

        return $value;
    }

    /**
     * Replace `${Const|Name}` by the value of Name in [Const], itself expanded.
     *
     * @param  array<string,array<string,string>> $ini
     * @param  array<string,string>               $others the constants of another file, used for the ones this one has not
     * @return array<string,array<string,string>>
     */
    private static function expand($ini, $others = [])
    {
        $const = ($ini['Const'] ?? []) + $others;
        foreach ($const as $key => $value) {
            $const[$key] = self::replace_constants($value, $const);
        }
        foreach ($ini as $section => $values) {
            foreach ($values as $key => $value) {
                $ini[$section][$key] = self::replace_constants($value, $const);
            }
        }

        return $ini;
    }
}
