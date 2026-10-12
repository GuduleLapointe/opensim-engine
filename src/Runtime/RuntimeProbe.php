<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Models\Core;

/**
 * Whether the machine can run a core: the runtime it needs (Mono, a .NET of the major it asks for or a newer one), the native
 * libraries it cannot start without. Says what is missing, with the command that installs it.
 */
class RuntimeProbe
{
    /**
     * @param ?\Closure(string):string $capture the output of a command, empty when it fails (default: the shell)
     * @param list<string> $libraryFolders where the libraries are looked for when the loader does not know them (a * is a wildcard)
     */
    public function __construct(
        private readonly ?\Closure $capture = null,
        private readonly array $libraryFolders = ['/usr/lib', '/usr/lib64', '/usr/lib/*', '/usr/local/lib'],
    ) {
    }

    /**
     * What stops the core from running with the runtime it asks for, null when nothing does.
     *
     * @param string $installer the command that installs .NET, as told in the message
     */
    public function problem(Core $core, string $assembly, string $installer = 'opensim install-dotnet'): ?string
    {
        $runtime = $core->runtime(preg_replace('/\.(dll|exe)$/', '', $assembly) ?? $assembly);
        if ($runtime === 'mono') {
            return $this->exists('mono') ? null : 'mono is not installed (required for OpenSim < 0.9.3.0). Install it, or run install/install.sh';
        }
        $major = (int) explode('.', $core->dotnetRequired($assembly))[0];
        if (!$this->exists('dotnet')) {
            return "dotnet is not installed. Install the .NET runtime with: sudo $installer $major";
        }
        // Roll-forward: any runtime at or above the required major can run the app, the lowest is used
        $installed = $this->dotnetMajors();
        foreach ($installed as $candidate) {
            if ($candidate >= $major) {
                return null;
            }
        }

        return "OpenSim needs Microsoft.NETCore.App $major or newer, but none is installed (installed: "
            . implode(' ', array_map(static fn(int $m): string => (string) $m, $installed))
            . "). Install .NET $major+ (sudo $installer $major) and retry";
    }

    /** Whether a native library is installed (Linux): known to the loader, or in the usual folders. Name without lib and .so. */
    public function hasLibrary(string $name): bool
    {
        if (str_contains($this->run('ldconfig -p 2>/dev/null || /sbin/ldconfig -p 2>/dev/null'), "lib$name.so")) {
            return true;
        }
        foreach ($this->libraryFolders as $folder) {
            // A link that leads nowhere (the library was removed) is not a library
            foreach (glob("$folder/lib$name.so*") ?: [] as $file) {
                if (file_exists($file)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<int> the majors of the .NET runtimes installed, ascending */
    private function dotnetMajors(): array
    {
        preg_match_all('/Microsoft\.NETCore\.App (\d+)\./', $this->run('dotnet --list-runtimes 2>/dev/null'), $m);
        $majors = array_values(array_unique(array_map('intval', $m[1])));
        sort($majors);

        return $majors;
    }

    private function exists(string $command): bool
    {
        return trim($this->run('command -v ' . escapeshellarg($command) . ' 2>/dev/null')) !== '';
    }

    private function run(string $command): string
    {
        return $this->capture !== null ? ($this->capture)($command) : (string) @shell_exec($command);
    }
}
