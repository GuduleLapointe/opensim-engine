<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Config\DirectoryLayout;
use OpenSim\Config\Layout;
use OpenSim\Runtime\Core;
use OpenSim\Runtime\ShellService;

/**
 * The system layout of a machine that has none: the account the instances run as, the folders of the layout with their owner, and
 * `opensim.conf`. It needs root; a client that is not root runs it through sudo (`needed()` says whether to).
 */
final class SystemLayout
{
    /** The account the instances run as when the install does not name another. */
    public const USER = 'opensim';

    /** Where the packaged cores are, before the default root of the layout. */
    public const PACKAGED_CORES = '/usr/share/opensim';

    /**
     * @param ?\Closure(string):int $run runs a command, returns its status (default: the shell)
     * @param ?\Closure(string):bool $userExists whether an account exists (default: the system's)
     * @param ?bool $root whether this process is root (default: it is when its user id is 0)
     */
    public function __construct(
        private readonly UiInterface $ui,
        private readonly ?\Closure $run = null,
        private readonly ?\Closure $userExists = null,
        private readonly ?bool $root = null,
    ) {}

    /** Whether the place of the grids cannot be written by whoever calls: the layout has to be made. */
    public static function needed(Layout $layout): bool
    {
        $existing = $layout->etcRoot();
        while ($existing !== dirname($existing) && !file_exists($existing)) {
            $existing = dirname($existing);
        }

        return !is_dir($existing) || !is_writable($existing);
    }

    /**
     * Make the account, the folders and `opensim.conf` (left as it is when it exists).
     *
     * @return bool whether the layout is there at the end
     */
    public function prepare(Layout $layout): bool
    {
        $root = $this->root ?? (function_exists('posix_geteuid') && posix_geteuid() === 0);
        if (!$root) {
            $this->ui->error(_('Making the system layout needs root: run it with sudo.'));

            return false;
        }
        $user = $layout->systemUser() !== '' ? $layout->systemUser() : self::USER;
        $home = $layout->varRoot();
        if (!$this->account($user)) {
            $this->ui->note(sprintf(_('Creating the account %s.'), $user));
            $made = $this->execute(
                ShellService::commandExists('adduser')
                    ? sprintf('adduser --system --group --home %s --shell /bin/bash %s', ShellService::arg($home), ShellService::arg($user))
                    : sprintf('useradd --system --user-group --home-dir %s --shell /bin/bash %s', ShellService::arg($home), ShellService::arg($user)),
            );
            if ($made !== 0) {
                $this->ui->error(sprintf(_('Could not create the account %s.'), $user));

                return false;
            }
        }
        $etc = $layout->etcRoot();
        foreach ([$etc, "$etc/grids", "$etc/presets", "$etc/opensim.d", "$etc/robust.d", $layout->varRoot(), $layout->dataRoot(), $layout->logsRoot(), $layout->cacheRoot()] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0o755, true)) {
                $this->ui->error(sprintf(_('Could not create %s.'), $dir));

                return false;
            }
            $this->execute(sprintf('chown %s:%s %s', ShellService::arg($user), ShellService::arg($user), ShellService::arg($dir)));
            @chmod($dir, 0o755);
        }
        $this->ui->note(sprintf(_('Folders ready in %s, %s, %s.'), $etc, $layout->varRoot(), $layout->logsRoot()));
        // The web server reads the ini files of the grids, which may hold a password: through the group of the account
        $this->execute("getent group www-data >/dev/null 2>&1 && usermod -aG www-data " . ShellService::arg($user));

        $conf = "$etc/opensim.conf";
        if (!is_file($conf)) {
            file_put_contents($conf, $this->conf($layout, $user));
            $this->execute(sprintf('chown %s:%s %s', ShellService::arg($user), ShellService::arg($user), ShellService::arg($conf)));
            $this->ui->note(sprintf(_('Wrote %s.'), $conf));
        }

        return true;
    }

    /** The content of a new `opensim.conf`: the roots of the layout, the core of the packages when there is one, the account. */
    private function conf(Layout $layout, string $user): string
    {
        $roots = DirectoryLayout::roots(DirectoryLayout::SYSTEM);
        $coreRoot = Core::latest(self::PACKAGED_CORES) !== null ? self::PACKAGED_CORES : $layout->coreRoot();
        $lines = ['[Defaults]', 'DirectoryLayout = ' . DirectoryLayout::SYSTEM, 'CoreRoot = ' . $coreRoot];
        foreach (['EtcRoot', 'VarRoot', 'LogsRoot', 'CacheRoot', 'DataRoot'] as $key) {
            $lines[] = "$key = " . ($layout->get($key) !== '' ? $layout->get($key) : ($roots[$key] ?? ''));
        }
        $lines[] = "SystemUser = $user";

        return implode("\n", $lines) . "\n";
    }

    private function account(string $user): bool
    {
        return ($this->userExists ?? static fn(string $name): bool => function_exists('posix_getpwnam') && posix_getpwnam($name) !== false)($user);
    }

    private function execute(string $command): int
    {
        return ($this->run ?? static fn(string $c): int => ShellService::run($c))($command);
    }
}
