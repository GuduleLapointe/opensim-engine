<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Config\GridFileService;
use OpenSim\Models\GridState;
use OpenSim\Provisioning\Web\HelpersConfig;
use OpenSim\Provisioning\Web\Snippets;
use OpenSim\Runtime\ShellService;

/**
 * The writing of the part of a setup that makes things: the files, the accounts, the instances. Run as the system user of the
 * install, root, or the user of an install without one. What it says goes to the interface it is given.
 */
final class GridProvisioner
{
    public function __construct(private UiInterface $ui)
    {
    }

    /** The writing itself: run as the system user of the install, root, or the user of an install without one. */
    public function apply(GridPlan $plan, array $profile): void
    {
        $etcRoot = $profile['EtcRoot'];
        $this->makeDirs($plan);
        $conf = (new GridConf())->write($plan);
        $this->ui->note(sprintf(_('Wrote %s'), $conf));
        $this->writeRobust($plan);
        $this->writeHelpers($plan);
        $this->copyConfigInclude($plan);

        $logConfig = (new RobustLogConfig())->write($plan);
        if ($logConfig !== null) {
            $this->ui->note(sprintf(_('Wrote %s'), $logConfig));
        }

        // What was asked is kept, to make the same grid again from a file (opensim import)
        SetupFile::record($plan->gridDir, static fn(array $data): array => SetupFile::withGrid($data, $plan));

        $systemUser = $profile['SystemUser'] ?? '';
        $this->giveToSystemUser($systemUser, [$plan->etcDirectory, $plan->dataDirectory, $plan->cacheDirectory], true);
        // Every ini file may hold a password, and the web server reads them: 640, the group of the web server
        if (!GridFileService::protect($plan->gridDir) && $plan->helpers) {
            $this->ui->warn(
                sprintf(
                    _(
                        'The ini files of %s may hold passwords and the web server must read them: give them to its group (chgrp, then chmod 640).',
                    ),
                    $plan->gridDir,
                ),
            );
        }

        if ($plan->enable) {
            if (GridState::enable($etcRoot, $plan->gridNick)) {
                $this->giveToSystemUser($systemUser, [GridState::link($etcRoot, $plan->gridNick)], false);
                $this->ui->note(sprintf(_('Enabled: %s'), GridState::link($etcRoot, $plan->gridNick)));
                if ($plan->start) {
                    $this->startGrid($plan);
                }
            } else {
                $this->ui->warn(_('Could not enable the grid (Robust config missing).'));
            }
        }

        $this->ui->note(sprintf(_("Grid '%s' configured."), $plan->gridName));
        if ($plan->helpers) {
            $this->ui->note(
                sprintf(
                    _(
                        'Web site: the helpers are in %s, the placeholder site in /usr/share/opensim-web/html (when the opensim-web package is installed). The configuration for your web server is in %s/web: %s.caddyfile, %s-nginx.conf, %s-apache.conf, to include in the site of the grid (opensim web %s snippet <caddy|nginx|apache> writes it again).',
                    ),
                    Snippets::WEBROOT,
                    $plan->gridDir,
                    $plan->gridNick,
                    $plan->gridNick,
                    $plan->gridNick,
                    $plan->gridNick,
                ),
            );
        }
    }
    /**
     * Start the grid and report whether it came up, by the result of the
     * launcher (a screen session is per user: the setup may not be the one
     * running the instances). A grid that does not start ends the setup.
     */
    private function startGrid(GridPlan $plan): void
    {
        $opensim = dirname(__DIR__, 3) . '/bin/opensim';
        $nick = $plan->gridNick;

        [$code, $said] = ShellService::runShown(ShellService::arg($opensim) . ' restart ' . ShellService::arg($nick));
        // The launcher waits for the ready signal in the console. After its
        // delay it says the instance is still starting, and succeeds: a
        // healthy Robust is ready within a minute, so that is a failure too.
        $pending = $code === 0 && str_contains($said, 'still starting');
        if ($code === 0 && !$pending) {
            $this->ui->note(sprintf(_("Grid '%s' is running."), $nick));

            return;
        }

        $this->ui->error(
            $pending
                ? sprintf(
                    _("Grid '%s' is configured but was not ready after two minutes. Try: %s -v start %s"),
                    $nick,
                    $opensim,
                    $nick,
                )
                : sprintf(_("Grid '%s' is configured but did not start. Try: %s -v start %s"), $nick, $opensim, $nick),
        );
        $log = $plan->logsDirectory . '/' . $plan->gridSlug . '_robust.log';
        if (is_file($log)) {
            $this->ui->note(_('Recent log:'));
            ShellService::run('tail -n 30 ' . ShellService::arg($log));
        }

        throw new SetupFailedException("Grid '$nick' did not start.");
    }
    /**
     * When the setup runs as root for an install with a system user (the
     * packages), what it creates belongs to that user, who runs the
     * instances. Nothing to do otherwise.
     *
     * @param list<string> $paths
     */
    private function giveToSystemUser(string $user, array $paths, bool $recursive): void
    {
        if (
            $user === '' ||
            !function_exists('posix_geteuid') ||
            posix_geteuid() !== 0 ||
            posix_getpwnam($user) === false
        ) {
            return;
        }
        foreach ($paths as $path) {
            if (is_link($path) || file_exists($path)) {
                ShellService::run(
                    'chown -h' . ($recursive ? 'R' : '') . ' ' . ShellService::arg($user) . ': ' . ShellService::arg($path),
                );
            }
        }
    }
    private function copyConfigInclude(GridPlan $plan): void
    {
        // The distribution, over it what the grid has set: a setting new in the distribution is there, what was chosen stays
        // (the same procedure as the folder of a preset), ready for the simulators that will join the grid
        (new GridShared())->merge($plan->etcDirectory, $plan->binDir, $plan->enableHypergrid);
        (new GridShared())->prepare($plan->etcDirectory, $plan->binDir, $plan->enableHypergrid);
        $this->ui->note(_('Copied config-include defaults.'));
    }
    /**
     * The files of the web side of the grid: the Helpers.ini that holds what has no official place (once: what the operator
     * changed in it is kept), and the configuration of the web server. The addresses, the name of the grid and the databases
     * are not copied in another file: the helpers read Robust.ini and OpenSim.ini.
     */
    private function writeHelpers(GridPlan $plan): void
    {
        if (!$plan->helpers) {
            return;
        }
        $path = HelpersConfig::path($plan->gridDir);
        if (!is_file($path)) {
            file_put_contents($path, HelpersConfig::template());
            $this->ui->note(sprintf(_('Wrote %s'), $path));
        }
        // The MoneyServer.ini, the original whole, with the key the currency helpers share with the MoneyServer (made at random once)
        if (MoneyServerConfig::original() === null) {
            $this->ui->warn(_('The original MoneyServer.ini is not in this install: the grid has none, and the currency is not served without its key.'));
        } elseif (($money = MoneyServerConfig::write($plan->gridDir)) !== null) {
            $this->ui->note(sprintf(_('Wrote %s'), $money));
        }

        // The configuration of the web server for this grid, one file for each server, to include in its site
        $webDir = "{$plan->gridDir}/web";
        is_dir($webDir) || mkdir($webDir, 0o755, true);
        // Named for the grid and the server, with the extension the editors know
        $names = [
            'caddy' => "{$plan->gridNick}.caddyfile",
            'nginx' => "{$plan->gridNick}-nginx.conf",
            'apache' => "{$plan->gridNick}-apache.conf",
        ];
        foreach (Snippets::SERVERS as $server) {
            $file = "$webDir/{$names[$server]}";
            file_put_contents($file, Snippets::render($server, $plan->gridNick, webUrl: $plan->webUrl));
            chmod($file, 0o644);
        }
        $this->ui->note(sprintf(_('Wrote the web server files in %s'), $webDir));
    }
    private function writeRobust(GridPlan $plan): void
    {
        $path = $plan->robustIni();
        if (is_file($path)) {
            @copy($path, "$path~"); // backup
        }
        file_put_contents($path, (new RobustConfig())->generate($plan));
        $this->ui->note(sprintf(_('Wrote %s'), $path));
    }
    private function makeDirs(GridPlan $plan): void
    {
        $dirs = [
            $plan->dataDirectory,
            "{$plan->dataDirectory}/fsassets",
            "{$plan->dataDirectory}/fsassets/data",
            "{$plan->dataDirectory}/maptiles",
            "{$plan->dataDirectory}/registry",
            $plan->cacheDirectory,
            "{$plan->cacheDirectory}/bakes",
            "{$plan->cacheDirectory}/fsassets",
            "{$plan->cacheDirectory}/fsassets/tmp",
            "{$plan->cacheDirectory}/maptiles",
            $plan->etcDirectory,
            "{$plan->etcDirectory}/assets",
            "{$plan->etcDirectory}/config-include",
            "{$plan->etcDirectory}/sims",
            "{$plan->etcDirectory}/inventory",
            "{$plan->etcDirectory}/robust-include",
            $plan->logsDirectory,
        ];
        foreach ($dirs as $dir) {
            if ($dir !== '' && !is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }
    }
}
