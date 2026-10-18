<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * An OpenSimulator core installed in a folder (`<root>/<version>`, with its `bin`): the executables to run and the runtime
 * they need. 0.9.3.0 and later are .NET assemblies (`.dll`, run with dotnet and a roll-forward), the older releases are Mono
 * (`.exe`). The rule is here and nowhere else.
 */
final class Core
{
    /** The first release built for .NET. */
    private const DOTNET_FROM = '0.9.3.0';

    private function __construct(private readonly string $directory)
    {
    }

    /** The core whose folder is given (the one that has `bin/`). */
    public static function at(string $directory): self
    {
        return new self(rtrim($directory, '/'));
    }

    /** The core a `bin` folder belongs to. */
    public static function ofBin(string $bin): self
    {
        return new self(dirname(rtrim($bin, '/')));
    }

    /**
     * The cores under a root: the releases by version first (older to newer), then the others (builds, unstable) by name.
     *
     * @return list<self>
     */
    public static function all(string $root): array
    {
        $root = rtrim($root, '/');
        $found = [];
        foreach (['exe', 'dll'] as $extension) {
            foreach (glob("$root/*/bin/OpenSim.$extension") ?: [] as $assembly) {
                $found[dirname($assembly, 2)] = true;
            }
        }
        $names = array_keys($found);
        usort($names, static fn(string $a, string $b): int => strnatcmp(basename($a), basename($b)));
        $releases = array_filter($names, static fn(string $d): bool => (bool) preg_match('/\d+(\.\d+)+$/', $d));
        $others = array_diff($names, $releases);

        return array_map(static fn(string $d): self => new self($d), [...$releases, ...$others]);
    }

    /**
     * The cores under a root as the choices of a menu: the folder and the name, in the same order as all().
     *
     * @return array<string,string> folder => name
     */
    public static function choices(string $root): array
    {
        $choices = [];
        foreach (self::all($root) as $core) {
            $choices[$core->directory()] = $core->name();
        }

        return $choices;
    }

    /** The latest core under a root: the newest release, else the last of the others. Null when there is none. */
    public static function latest(string $root): ?self
    {
        if ($root === '') {
            return null;
        }
        $cores = self::all($root);
        $releases = array_values(array_filter($cores, static fn(self $c): bool => (bool) preg_match('/\d+(\.\d+)+$/', $c->directory)));
        $pick = $releases !== [] ? $releases : $cores;

        return $pick === [] ? null : $pick[count($pick) - 1];
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function bin(): string
    {
        return $this->directory . '/bin';
    }

    /** The name of the folder: the version for a release, the name of the build otherwise. */
    public function name(): string
    {
        return basename($this->directory);
    }

    /** Whether the folder holds a main assembly: OpenSim.exe in the releases, only OpenSim.dll in the builds made on Linux. */
    public function exists(): bool
    {
        return $this->mainAssembly() !== null;
    }

    /** The main assembly, null when the folder holds none. */
    public function mainAssembly(): ?string
    {
        foreach (['exe', 'dll'] as $extension) {
            if (is_file($this->bin() . "/OpenSim.$extension")) {
                return $this->bin() . "/OpenSim.$extension";
            }
        }

        return null;
    }

    /** The version in the path of the bin folder ("0.9.3.0"), empty when it has none. */
    public function version(): string
    {
        return preg_match('/\d+(?:\.\d+)+/', $this->bin(), $m) ? $m[0] : '';
    }

    /** The runtime of an assembly: dotnet for the builds that come with a runtimeconfig.json, else by the version. */
    public function runtime(string $base = 'OpenSim'): string
    {
        return is_file($this->bin() . "/$base.runtimeconfig.json") || version_compare($this->version() ?: '0', self::DOTNET_FROM, '>=')
            ? 'dotnet'
            : 'mono';
    }

    /** The options the runtime is given before the assembly. */
    public function runtimeOptions(string $base = 'OpenSim'): string
    {
        return $this->runtime($base) === 'dotnet' ? '--roll-forward Major' : '';
    }

    /** The file of an assembly by its base name (Robust, OpenSim, MoneyServer...): Robust.dll or Robust.exe. */
    public function executable(string $base): string
    {
        return $base . ($this->runtime($base) === 'dotnet' ? '.dll' : '.exe');
    }

    /** The .NET version an assembly asks for, from its runtimeconfig.json (8.0.0 when it does not say). */
    public function dotnetRequired(string $executable): string
    {
        $config = $this->bin() . '/' . preg_replace('/\.dll$/', '', $executable) . '.runtimeconfig.json';
        if (is_file($config) && preg_match('/Microsoft\.NETCore\.App.{0,80}?(\d+\.\d+\.\d+)/s', (string) file_get_contents($config), $m)) {
            return $m[1];
        }

        return '8.0.0';
    }

    /**
     * The native libraries of the core (physics, OpenJPEG) by the names .NET looks for: it does not read the dllmap files of a
     * core. The core being read-only, a folder of links with these names for this architecture is made in the base folder, for
     * the core being run. Nothing when it cannot be made.
     *
     * @param ?string $architecture uname -m, null for this machine
     * @return string the folder of the links, empty when there is none
     */
    public function nativeLibraries(string $base, ?string $architecture = null): string
    {
        $architecture ??= php_uname('m');
        $arch = in_array($architecture, ['aarch64', 'arm64'], true) ? 'arm64' : 'x86_64';
        $lib64 = $this->bin() . '/lib64';
        $destination = rtrim($base, '/') . '/' . $this->name();
        if (!is_dir($lib64) || (!is_dir($destination) && !mkdir($destination, 0o755, true) && !is_dir($destination))) {
            return '';
        }
        foreach (['BulletSim', 'openjpeg-dotnet', 'ubode'] as $library) {
            // The build of this architecture, else the plain one (x86_64 only)
            $file = "$lib64/lib$library-$arch.so";
            if (!is_file($file) && $arch === 'x86_64') {
                $file = "$lib64/lib$library.so";
            }
            if (is_file($file)) {
                $link = "$destination/lib$library.so";
                if (is_link($link)) {
                    unlink($link);
                }
                symlink($file, $link);
            }
        }

        return $destination;
    }
}
