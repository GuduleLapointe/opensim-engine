<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Provisioning\UiInterface;

/**
 * Installs what OpenSimulator needs to run on this machine, when it is missing: the native library libgdiplus, which the regions
 * draw their dynamic textures with and stop at once without, and the .NET runtime, from the system packages when they have it,
 * from a runtime installed elsewhere, or with the install script of Microsoft.
 *
 * As root the runtime is installed for every user, the service account included; otherwise in ~/.dotnet. The questions go through
 * the interface it is given, which answers them its own way (a person, or the defaults).
 */
final class DotnetInstaller
{
    private const SCRIPT = 'https://builds.dotnet.microsoft.com/dotnet/scripts/v1/dotnet-install.sh';

    /** @var \Closure(string):string */
    private \Closure $capture;

    /** @var \Closure(string):int */
    private \Closure $run;

    /**
     * @param ?\Closure(string):string $capture the output of a command, empty when it fails (default: the shell)
     * @param ?\Closure(string):int $run runs a command, its output shown, gives its status (default: the shell)
     * @param ?bool $root whether this process is root (default: it is told)
     * @param string $linkDirectory where `dotnet` is linked for every user, when root
     */
    public function __construct(
        private readonly UiInterface $ui,
        ?\Closure $capture = null,
        ?\Closure $run = null,
        private readonly ?bool $root = null,
        private readonly string $os = PHP_OS_FAMILY,
        private readonly string $home = '',
        private readonly string $linkDirectory = '/usr/local/bin',
    ) {
        $this->capture = $capture ?? static fn(string $command): string => ShellService::capture($command)[1];
        $this->run = $run ?? static fn(string $command): int => ShellService::run($command);
    }

    /**
     * Make a .NET runtime of this major version, or a newer one, available.
     *
     * @return bool whether a runtime that fits is there at the end
     */
    public function install(int $major = 8): bool
    {
        $this->gdiplus();

        $found = $this->suitable('dotnet', $major);
        if ($found !== null) {
            $this->ui->note(
                sprintf(
                    _('.NET %d found (%s), it can run OpenSim (.NET %d or newer).'),
                    $found,
                    $this->path('dotnet'),
                    $major,
                ),
            );

            return true;
        }
        $this->ui->note(sprintf(_('OpenSim needs the .NET %d runtime (or newer), not found on the PATH.'), $major));

        // The system packages: Ubuntu, or Debian with the repository of Microsoft
        $package = $this->systemRuntimePackage($major);
        if ($package !== null) {
            if (!$this->isRoot()) {
                $this->ui->error(sprintf(_('Install it as root: sudo apt install %s'), $package));

                return false;
            }
            if ($this->ui->confirm(sprintf(_('Install %s from the system packages?'), $package), true)) {
                if (($this->run)("apt-get install -y $package") !== 0) {
                    $this->ui->error(sprintf(_('Could not install %s'), $package));

                    return false;
                }

                return true;
            }
        }

        // A runtime installed elsewhere, not on the PATH
        $existing = trim($this->ui->text(_('Path of an installed dotnet executable (empty to install one)'), ''));
        if ($existing !== '') {
            is_dir($existing) && ($existing = rtrim($existing, '/') . '/dotnet');
            if (!is_executable($existing)) {
                $this->ui->error(sprintf(_('%s is not an executable'), $existing));

                return false;
            }
            if ($this->suitable($existing, $major) === null) {
                $this->ui->error(sprintf(_('%s has no .NET %d runtime (or newer)'), $existing, $major));

                return false;
            }
            $this->link((string) (realpath($existing) ?: $existing));

            return true;
        }

        return $this->fromScript($major);
    }

    /** The native library of the regions: installed from the system packages when this process may, else said. */
    private function gdiplus(): void
    {
        if ($this->os !== 'Linux' || $this->hasLibrary('gdiplus')) {
            return;
        }
        if ($this->isRoot() && $this->candidate('libgdiplus')) {
            if (
                $this->ui->confirm(_('Install libgdiplus, needed by the regions?'), true) &&
                ($this->run)('apt-get install -y libgdiplus') !== 0
            ) {
                $this->ui->error(_('Could not install libgdiplus'));
            }

            return;
        }
        $this->ui->warn(
            _(
                'OpenSim regions also need libgdiplus: install it with your package manager (e.g. sudo apt install libgdiplus).',
            ),
        );
    }

    /** The install script of Microsoft, into a folder for every user as root, in the home of the person otherwise. */
    private function fromScript(int $major): bool
    {
        $home = $this->home !== '' ? $this->home : (string) getenv('HOME');
        $default = $this->isRoot() ? '/usr/local/share/dotnet' : "$home/.dotnet";
        $dir = trim($this->ui->text(_('Install directory'), $default)) ?: $default;
        if (
            !$this->ui->confirm(
                sprintf(_("Install the .NET %d runtime in %s with Microsoft's install script?"), $major, $dir),
                true,
            )
        ) {
            $this->ui->error(sprintf(_('OpenSim cannot run without the .NET %d runtime'), $major));

            return false;
        }

        // The runtime needs ICU, which a minimal system may lack
        if (
            $this->isRoot() &&
            $this->exists('apt-get') &&
            !str_contains(($this->capture)('ldconfig -p'), 'libicuuc.so')
        ) {
            $names = array_filter(
                explode("\n", ($this->capture)('apt-cache pkgnames libicu')),
                static fn(string $name): bool => preg_match('/^libicu\d+$/', $name) === 1,
            );
            usort($names, 'strnatcmp');
            if ($names !== [] && ($this->run)('apt-get install -y ' . escapeshellarg((string) end($names))) !== 0) {
                $this->ui->error(sprintf(_('Could not install %s'), (string) end($names)));

                return false;
            }
        }

        if (!$this->exists('curl')) {
            $this->ui->error(_('curl is needed to download the install script'));

            return false;
        }
        $script = sys_get_temp_dir() . '/dotnet-install-' . bin2hex(random_bytes(4)) . '.sh';
        try {
            if (($this->run)('curl -fsSL ' . self::SCRIPT . ' -o ' . escapeshellarg($script)) !== 0) {
                $this->ui->error(_('Could not download dotnet-install.sh'));

                return false;
            }
            $status = ($this->run)(
                'bash ' .
                    escapeshellarg($script) .
                    ' --runtime dotnet --channel ' .
                    escapeshellarg("$major.0") .
                    ' --install-dir ' .
                    escapeshellarg($dir),
            );
        } finally {
            is_file($script) && unlink($script);
        }
        if ($status !== 0) {
            $this->ui->error(sprintf(_('.NET %d runtime installation failed'), $major));

            return false;
        }
        $this->link("$dir/dotnet");

        return true;
    }

    /** Make a dotnet reachable by every user (root), or tell how. */
    private function link(string $dotnet): void
    {
        if ($this->isRoot()) {
            $link = rtrim($this->linkDirectory, '/') . '/dotnet';
            (is_link($link) || is_file($link)) && unlink($link);
            if (!symlink($dotnet, $link)) {
                $this->ui->error(sprintf(_('Could not link %s into %s'), $dotnet, $this->linkDirectory));

                return;
            }
            $this->ui->note(sprintf(_('Linked %s to %s'), $link, $dotnet));

            return;
        }
        $this->ui->note(
            sprintf(
                _("Add %s to your PATH, e.g. in ~/.profile:\n  export PATH=\"%s:\$PATH\""),
                dirname($dotnet),
                dirname($dotnet),
            ),
        );
    }

    /** The lowest major of the runtimes of a dotnet that is this one or a newer one, null when there is none. */
    private function suitable(string $dotnet, int $major): ?int
    {
        preg_match_all(
            '/Microsoft\.NETCore\.App (\d+)/',
            ($this->capture)(escapeshellarg($dotnet) . ' --list-runtimes 2>/dev/null'),
            $m,
        );
        $majors = array_filter(
            array_unique(array_map('intval', $m[1])),
            static fn(int $found): bool => $found >= $major,
        );
        sort($majors);

        return $majors[0] ?? null;
    }

    private function path(string $command): string
    {
        return trim(($this->capture)('command -v ' . escapeshellarg($command)));
    }

    private function exists(string $command): bool
    {
        return $this->path($command) !== '';
    }

    private function hasLibrary(string $name): bool
    {
        return str_contains(
            ($this->capture)('ldconfig -p 2>/dev/null || /sbin/ldconfig -p 2>/dev/null'),
            "lib$name.so",
        );
    }

    /** Whether the package manager has a package to install. */
    private function candidate(string $package): bool
    {
        if (!$this->exists('apt-cache')) {
            return false;
        }
        foreach (explode("\n", ($this->capture)('apt-cache policy ' . escapeshellarg($package))) as $line) {
            if (preg_match('/^\s*Candidate:\s*(\S+)/', $line, $m) === 1) {
                return $m[1] !== '(none)';
            }
        }

        return false;
    }

    /** The newest system runtime package that can run the requested major. */
    private function systemRuntimePackage(int $major): ?string
    {
        if (!$this->exists('apt-cache')) {
            return null;
        }

        $packages = array_filter(
            explode("\n", ($this->capture)('apt-cache pkgnames dotnet-runtime-')),
            static fn(string $package): bool =>
                preg_match('/^dotnet-runtime-(\d+)\.0$/', $package, $matches) === 1 && (int) $matches[1] >= $major,
        );
        $packages[] = "dotnet-runtime-$major.0";
        $packages = array_values(array_unique($packages));
        usort($packages, static fn(string $a, string $b): int => version_compare($b, $a));

        foreach ($packages as $package) {
            if ($this->candidate($package)) {
                return $package;
            }
        }

        return null;
    }

    private function isRoot(): bool
    {
        return $this->root ??
            (function_exists('posix_geteuid') ? posix_geteuid() === 0 : trim(($this->capture)('id -u')) === '0');
    }
}
