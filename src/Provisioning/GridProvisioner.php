<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Exceptions\SetupFailedException;
use OpenSim\Config\GridFileService;
use OpenSim\Config\IniEditor;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\Web\HelpersConfig;
use OpenSim\Provisioning\Web\Snippets;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\ShellService;
use OpenSim\Config\GridConfig;

/**
 * The writing of the part of a setup that makes things: the files, the accounts, the instances. Run as the system user of the
 * install, root, or the user of an install without one. What it says goes to the interface it is given.
 */
final class GridProvisioner
{
    public function __construct(private UiInterface $ui) {}

    /** The writing itself: run as the system user of the install, root, or the user of an install without one. */
    public function apply(GridPlan $plan, Environment $environment): void
    {
        $this->makeDirs($plan);
        $this->copyCoreData($plan);
        $conf = (new GridConf())->write($plan);
        $this->ui->note(sprintf(_('Wrote %s'), $conf));
        // A standalone has no Robust: its simulator serves the grid
        $plan->standalone || $this->writeRobust($plan);
        $this->writeHelpers($plan);
        $this->copyConfigInclude($plan);
        $this->applyOptions($plan, $environment);
        $this->writeMaster($plan);

        $logConfig = $plan->standalone ? null : (new RobustLogConfig())->write($plan);
        if ($logConfig !== null) {
            $this->ui->note(sprintf(_('Wrote %s'), $logConfig));
        }

        // What was asked is kept, to make the same grid again from a file (opensim import)
        SetupFile::record($plan->gridDir, static fn(array $data): array => SetupFile::withGrid($data, $plan));

        $systemUser = $environment->layout->systemUser();
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

        // A standalone is enabled and started with its simulator
        if ($plan->enable && !$plan->standalone) {
            $grid = Grid::at($environment, $plan->gridDir);
            if ($grid->enable()) {
                $link = (string) $grid->robust()?->link();
                $this->giveToSystemUser($systemUser, [$link], false);
                $this->ui->note(sprintf(_('Enabled: %s'), $link));
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
                        'Web site: the helpers are in %s. The configuration for your web server is in %s/web, to include in the site of the grid (opensim web %s snippet <caddy|nginx|apache> writes it for another server).',
                    ),
                    Snippets::WEBROOT,
                    $plan->gridDir,
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
        $nick = $plan->gridNick;

        [$code, $said] = InstanceControl::run($this->ui, 'restart', $nick);
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
                    'opensim',
                    $nick,
                )
                : sprintf(_("Grid '%s' is configured but did not start. Try: %s -v start %s"), $nick, 'opensim', $nick),
        );
        $log = $plan->logsDirectory . '/' . Slug::snake($plan->gridSlug) . '_robust.log';
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
                    'chown -h' .
                        ($recursive ? 'R' : '') .
                        ' ' .
                        ShellService::arg($user) .
                        ': ' .
                        ShellService::arg($path),
                );
            }
        }
    }
    /**
     * The assets and the library of the core, for the grid: copied into its data folder when it has none or when they are empty (what
     * it has is its own and stays), where the config of Robust and of the simulators reads them. The library the users share is added
     * to this copy, the core stays untouched.
     */
    private function copyCoreData(GridPlan $plan): void
    {
        foreach (['assets', 'inventory'] as $name) {
            $to = "{$plan->dataDirectory}/$name";
            $from = "{$plan->binDir}/$name";
            if (!is_dir($from) || ($has = glob("$to/*")) !== false && $has !== []) {
                continue;
            }
            is_dir($to) || @mkdir($to, 0o755, true);
            GridFileService::copyTree($from, $to, false);
            $this->ui->note(sprintf(_('Copied %s from the core to %s.'), $name, $to));
        }
    }

    private function copyConfigInclude(GridPlan $plan): void
    {
        // The distribution, over it what the grid has set: a setting new in the distribution is there, what was chosen stays
        // (the same procedure as the folder of a preset), ready for the simulators that will join the grid
        $shared = new GridShared($plan->dataDirectory);
        $shared->merge($plan->etcDirectory, $plan->binDir, $plan->enableHypergrid, standalone: $plan->standalone);
        $shared->prepare($plan->etcDirectory, $plan->binDir, $plan->enableHypergrid, $plan->standalone);
        $this->ui->note(_('Copied config-include defaults.'));
        if ($plan->standalone && ($services = (new GridServices())->writeStandalone($plan)) !== null) {
            $this->ui->note(sprintf(_('Wrote %s'), $services));
        }
    }
    /** The options of the grid (see GridOptions): each one the setup chose is laid over the files. */
    private function applyOptions(GridPlan $plan, Environment $environment): void
    {
        $options = new GridOptions(Grid::planned($environment, $plan), layered: true);
        $report = function (string $name, array $result): void {
            $result['error'] === null ||
                $this->ui->warn(sprintf(_('The option %s was not applied: %s'), $name, $result['error']));
            foreach ($result['files'] as $file) {
                $this->ui->note(sprintf(_('Wrote %s'), $file));
            }
            foreach ($result['notes'] as $note) {
                $this->ui->note($note);
            }
        };
        foreach ($plan->options as $name) {
            $report($name, $options->enable($name));
        }
    }

    /**
     * The master of the simulators of the grid, made from the core, the model of the toolset, the grid and the options it has. The
     * simulators say only what makes them their own.
     */
    private function writeMaster(GridPlan $plan): void
    {
        if (!is_file("{$plan->etcDirectory}/OpenSim.ini")) {
            return;
        }
        $master = (new SimulatorMaster())->ensure($plan->etcDirectory, $plan->binDir);
        $master !== null && $this->ui->note(sprintf(_('Wrote %s'), $master));
    }

    /**
     * The web side of the grid: the file of the helpers (`Helpers.ini`) and the configuration of the web server are options (see
     * GridOptions). What is left is the name of the file of the helpers in the case a system that does not tell them apart holds.
     */
    private function writeHelpers(GridPlan $plan): void
    {
        foreach (HelpersConfig::unifyCase($plan->gridDir) as [$from, $to]) {
            $this->ui->note(
                sprintf(_('Renamed %s to %s: the file of the helpers is %s.'), $from, $to, HelpersConfig::FILE),
            );
        }
        if (!$plan->helpers) {
            return;
        }
        // The MoneyServer.ini, the original whole, with the key the currency helpers share with the MoneyServer (made at random once):
        // for a currency that runs a MoneyServer, which none of the ones the setup offers does (beta, gloebit)
        if ($plan->economy === 'moneyserver') {
            if (MoneyServerConfig::original() === null) {
                $this->ui->warn(
                    _(
                        'The original MoneyServer.ini is not in this install: the grid has none, and the currency is not served without its key.',
                    ),
                );
            } elseif (($money = MoneyServerConfig::write($plan->gridDir)) !== null) {
                $this->ui->note(sprintf(_('Wrote %s'), $money));
            }
        }
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
            "{$plan->etcDirectory}/config-include",
            GridConfig::simulatorsDirectory($plan->etcDirectory),
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
