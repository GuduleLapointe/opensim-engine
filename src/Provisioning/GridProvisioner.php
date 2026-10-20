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
        $conf = (new GridConf())->write($plan);
        $this->ui->note(sprintf(_('Wrote %s'), $conf));
        // A standalone has no Robust: its simulator serves the grid
        $plan->standalone || $this->writeRobust($plan);
        $this->writeHelpers($plan);
        $this->copyConfigInclude($plan);
        $this->writeMaster($plan, $environment);
        $this->applyOptions($plan, $environment);

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
    private function copyConfigInclude(GridPlan $plan): void
    {
        // The distribution, over it what the grid has set: a setting new in the distribution is there, what was chosen stays
        // (the same procedure as the folder of a preset), ready for the simulators that will join the grid
        $shared = new GridShared();
        $shared->merge($plan->etcDirectory, $plan->binDir, $plan->enableHypergrid, standalone: $plan->standalone);
        $shared->prepare($plan->etcDirectory, $plan->binDir, $plan->enableHypergrid, $plan->standalone);
        $this->ui->note(_('Copied config-include defaults.'));
        if ($plan->standalone && ($services = (new GridServices())->writeStandalone($plan)) !== null) {
            $this->ui->note(sprintf(_('Wrote %s'), $services));
        }
    }
    /**
     * The options of a grid that is made, which the files do not carry by themselves: more OSSL (an ini file combined into the one of
     * the grid) and the currency. The default of each is what the files already say, nothing to do.
     */
    private function applyOptions(GridPlan $plan, Environment $environment): void
    {
        foreach (GridOptions::enabled($plan) as $option) {
            $target = GridOptions::target($option);
            if (
                $target !== null &&
                ($file = GridOptions::combine($option, "{$plan->etcDirectory}/$target")) !== null
            ) {
                $this->ui->note(sprintf(_('Wrote %s'), $file));
            }
        }
        if ($plan->economy !== 'gloebit') {
            return;
        }
        $grid = Grid::find($environment, $plan->gridNick);
        $result =
            $grid === null
                ? ['error' => 'the grid is not there', 'notes' => []]
                : (new Features($grid))->enable(Features::ECONOMY, ['money' => 'gloebit']);
        $result['error'] === null ||
            $this->ui->warn(sprintf(_('The currency of the grid was not set: %s'), $result['error']));
        foreach ($result['notes'] as $note) {
            $this->ui->note($note);
        }
    }

    /**
     * The master of the simulators of the grid: what the grid decides for them (the search, the offline messages, the profiles)
     * goes in its OpenSim.ini, which the helpers read, when the grid is made (after that the operator and `opensim enable|disable`
     * decide: a feature that was disabled stays so), then the master is made from the core, the model of the toolset, the grid and
     * these decisions. The simulators say only what makes them their own.
     */
    private function writeMaster(GridPlan $plan, Environment $environment): void
    {
        $general = "{$plan->etcDirectory}/OpenSim.ini";
        if (!is_file($general)) {
            return;
        }
        if (!SimulatorMaster::exists($plan->etcDirectory)) {
            $ini = IniEditor::load($general);
            $before = $ini->toString();
            $ini->overlay(implode("\n", SimulatorConfig::gridLayer(Grid::planned($environment, $plan))));
            if ($ini->toString() !== $before) {
                $ini->save($general);
            }
        }
        $master = (new SimulatorMaster())->ensure($plan->etcDirectory, $plan->binDir);
        $master !== null && $this->ui->note(sprintf(_('Wrote %s'), $master));
    }

    /**
     * The files of the web side of the grid: the Helpers.ini that holds what has no official place (once: what the operator
     * changed in it is kept), and the configuration of the web server. The addresses, the name of the grid and the databases
     * are not copied in another file: the helpers read Robust.ini and OpenSim.ini.
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
        $path = HelpersConfig::path($plan->gridDir);
        if (!is_file($path)) {
            file_put_contents($path, HelpersConfig::template());
            $this->ui->note(sprintf(_('Wrote %s'), $path));
        }
        // The MoneyServer.ini, the original whole, with the key the currency helpers share with the MoneyServer (made at random once)
        if (MoneyServerConfig::original() === null) {
            $this->ui->warn(
                _(
                    'The original MoneyServer.ini is not in this install: the grid has none, and the currency is not served without its key.',
                ),
            );
        } elseif (($money = MoneyServerConfig::write($plan->gridDir)) !== null) {
            $this->ui->note(sprintf(_('Wrote %s'), $money));
        }

        // The configuration of the web server chosen, to include in the site of the grid. Named for the grid and the server, with the
        // extension the editors know. A grid that has one keeps it: it is the operator's
        $webDir = "{$plan->gridDir}/web";
        $names = [
            'caddy' => "{$plan->gridNick}.caddyfile",
            'nginx' => "{$plan->gridNick}-nginx.conf",
            'apache' => "{$plan->gridNick}-apache.conf",
        ];
        $server = in_array($plan->webServer, Snippets::SERVERS, true) ? $plan->webServer : 'caddy';
        $kept = array_filter($names, static fn(string $name): bool => is_file("$webDir/$name"));
        if ($kept === []) {
            is_dir($webDir) || mkdir($webDir, 0o755, true);
            $file = "$webDir/{$names[$server]}";
            $docroot = $plan->webSite === 'website' ? Snippets::WEBSITE : Snippets::DOCROOT;
            file_put_contents($file, Snippets::render($server, $plan->gridNick, $docroot, webUrl: $plan->webUrl));
            chmod($file, 0o644);
            $this->ui->note(sprintf(_('Wrote %s'), $file));
        }
        if ($plan->webSite === 'website' && !is_dir(Snippets::WEBSITE)) {
            $this->ui->warn(_('The mini website is the package opensim-web: install it (apt install opensim-web).'));
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
            "{$plan->etcDirectory}/assets",
            "{$plan->etcDirectory}/config-include",
            GridConfig::simulatorsDirectory($plan->etcDirectory),
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
