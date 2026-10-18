<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Exceptions\SetupFailedException;
use OpenSim\Config\GridFileService;
use OpenSim\Console\InstanceConsoleService;
use OpenSim\Models\Grid;
use OpenSim\Models\Region;
use OpenSim\Models\Simulator;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\ShellService;

/**
 * The writing of the part of a setup that makes things: the files, the accounts, the instances. Run as the system user of the
 * install, root, or the user of an install without one. What it says goes to the interface it is given.
 */
final class SimulatorProvisioner
{
    public function __construct(private UiInterface $ui)
    {
    }

    /**
     * The writing itself: run as the system user of the install, root, or the
     * user of an install without one.
     */
    public function apply(SimulatorPlan $plan, Environment $environment): void
    {
        if ($plan->remoteGrid !== null) {
            $this->ui->note(sprintf(_('Wrote %s'), (new GridConf())->writeRemote($plan->remoteGrid)));
        }
        $grid = Grid::find($environment, $plan->gridNick);
        if ($grid === null) {
            $this->ui->error(sprintf(_("Grid '%s' not found."), $plan->gridNick));

            throw new SetupFailedException('grid');
        }

        if ($plan->regionOnly) {
            $this->applyRegion($plan, $environment, $grid);
            SetupFile::record($plan->gridDir, static fn(array $data): array => SetupFile::withSimulator($data, $plan));

            return;
        }

        // First, while nothing is written: the account the estate needs
        if ($plan->createOwner) {
            $accounts = new GridAccounts(new DatabaseService($this->ui), $this->ui);
            if (!$accounts->create($grid, $plan->estateOwner, $plan->ownerPassword, $plan->ownerEmail)) {
                throw new SetupFailedException('account');
            }
            $this->ui->note(sprintf(_("Account %s created in the grid '%s'."), $plan->estateOwner, $grid->nick()));
        }

        foreach (
            [
                $plan->dataDirectory,
                "{$plan->dataDirectory}/registry",
                $plan->cacheDirectory,
                "{$plan->cacheDirectory}/maptiles",
                $plan->regionsDir(),
                $plan->logsDirectory,
            ]
            as $dir
        ) {
            if ($dir !== '' && !is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }

        if ($plan->preset !== '') {
            // A grid joined by a preset: its folder in presets/ is made complete, the same way every time (the distribution
            // merged with the preset), and the simulator reads it there
            $presets = new Presets([], $environment->layout->etcRoot());
            foreach ($presets->install($plan->preset, $plan->binDir(), $plan->hypergrid) as $file) {
                $this->ui->note(sprintf(_('Wrote %s'), $file));
            }
            $plan->presetSettings = $presets->settings($plan->preset, $plan->binDir());
            // What applies to all its simulators is in the preset: the master of the simulators is made from it, as a grid's is made
            // from its own OpenSim.ini. The simulators say only what makes them their own
            $master = (new SimulatorMaster())->ensure($plan->gridDir, $plan->binDir());
            $master !== null && $this->ui->note(sprintf(_('Wrote %s'), $master));
        } else {
            foreach ((new GridShared())->prepare($plan->gridDir, $plan->binDir(), $plan->hypergrid) as $file) {
                $this->ui->note(sprintf(_('Wrote %s'), $file));
            }
        }
        $this->ui->note(sprintf(_('Wrote %s'), (new SimulatorConfig())->write($plan)));
        if ($plan->createRegion) {
            $region = (new SimulatorConfig())->writeRegion($plan);
            $this->ui->note(
                $region !== null
                    ? sprintf(_('Wrote %s'), $region)
                    : sprintf(_('Region %s already described, left as it is.'), $plan->regionName),
            );
        }

        if ($plan->createRegion) {
            $this->giveRoles($plan, $grid);
        }

        SetupFile::record($plan->gridDir, static fn(array $data): array => SetupFile::withSimulator($data, $plan));

        $systemUser = $environment->layout->systemUser();
        $this->giveToSystemUser(
            $systemUser,
            array_filter([$plan->gridDir, $plan->preset !== '' ? $plan->configDir : '', $plan->dataDirectory, $plan->cacheDirectory]),
            true,
        );
        // The ini files may hold passwords and the web server reads them (the helpers): 640, the group of the web server
        GridFileService::protect($plan->gridDir);

        if ($plan->enable) {
            $simulator = new Simulator($environment, $plan->iniPath());
            if ($simulator->enable()) {
                $this->giveToSystemUser($systemUser, [$simulator->link()], false);
                $this->ui->note(sprintf(_('Enabled: %s'), $simulator->link()));
                if ($plan->start) {
                    $this->startSimulator($plan, $grid, $environment);
                }
            } else {
                $this->ui->warn(_('Could not enable the simulator (config missing).'));
            }
        }

        $this->ui->note(sprintf(_("Simulator '%s' configured."), $plan->simulatorName));
    }
    /**
     * Start the simulator and report whether it came up: the launcher's
     * answer, then, for a new region, its registration in the grid.
     */
    private function startSimulator(SimulatorPlan $plan, Grid $grid, Environment $environment): void
    {

        // A region registers with the grid when it starts: the grid has to run (when
        // it is ours: a Robust on another machine is its administrator's)
        if (!$grid->isRemote()) {
            // Robust reads the roles of its regions when it starts: it starts again to give them
            [$code] = InstanceControl::run($this->ui, $plan->regionRoles !== [] ? 'restart' : 'start', $grid->nick(), true);
            if ($code !== 0) {
                $this->failed(
                    $plan,
                    sprintf(
                        _(
                            "The grid '%s' is not running, and a simulator cannot start without it. Enable and start it first: %s -v start %s",
                        ),
                        $grid->nick(),
                        'opensim',
                        $grid->nick(),
                    ),
                );
            }
        }

        // The simulator restarts at once, unless its users are to be warned first (see opensim restart)
        $now = $plan->warnUsers ? '' : 'now ';
        [$code, $said] = InstanceControl::run($this->ui, 'restart', $plan->slug, $now !== '');
        $pending = $code === 0 && str_contains($said, 'still starting');
        if ($code === 0 && !$pending) {
            if ($plan->createRegion && !$grid->isRemote() && !$this->registered($plan, $grid)) {
                $this->failed(
                    $plan,
                    sprintf(
                        _("Simulator '%s' runs, but the region %s did not register in the grid '%s'."),
                        $plan->simulatorName,
                        $plan->regionName,
                        $grid->nick(),
                    ),
                );
            }
            if ($plan->createRegion) {
                $this->giveHome($plan, $grid);
                $this->initRegion($plan, $environment);
            }
            $this->ui->note(
                !$plan->createRegion
                    ? sprintf(_("Simulator '%s' is running."), $plan->simulatorName)
                    : ($grid->isRemote()
                        ? sprintf(
                            _(
                                "Simulator '%s' is running, region %s started: see the grid, on its map, for its registration.",
                            ),
                            $plan->simulatorName,
                            $plan->regionName,
                        )
                        : sprintf(
                            _("Simulator '%s' is running, region %s is online."),
                            $plan->simulatorName,
                            $plan->regionName,
                        )),
            );

            return;
        }

        $this->failed(
            $plan,
            $pending
                ? "Simulator '{$plan->simulatorName}' is configured but was not ready after two minutes. Try: opensim -v start {$plan->slug}"
                : "Simulator '{$plan->simulatorName}' is configured but did not start. Try: opensim -v start {$plan->slug}",
        );
    }
    private function failed(SimulatorPlan $plan, string $message): never
    {
        $this->ui->error($message);
        $log = $plan->logsDirectory . '/' . $plan->slug . '.log';
        if (is_file($log)) {
            $this->ui->note(_('Recent log:'));
            ShellService::run('tail -n 30 ' . ShellService::arg($log));
        }

        throw new SetupFailedException($message);
    }
    /**
     * What a new region gets once it runs: the object of share/ossl-scripts, loaded through the console of the
     * simulator, its script names the parcel after the region. --merge leaves the terrain, the parcels and the
     * objects of the region alone; the objects of the archive go to the estate owner, and to the middle of the
     * region (the archive was saved with the object in the middle of a 256 m one).
     *
     * Not fatal: a region without it is a region whose parcel is named "Your Parcel".
     */
    private function initRegion(SimulatorPlan $plan, Environment $environment): void
    {
        $archive = $this->initArchive();
        if ($archive === null || RegionName::problem($plan->regionName) !== null) {
            return;
        }
        $shift = (int) ($plan->regionSize / 2) - 128;
        $sent = InstanceConsoleService::send(
            $plan->slug,
            "change region \"{$plan->regionName}\"\n" .
                "load oar --merge --default-user \"{$plan->estateOwner}\" --displacement \"<$shift,$shift,0>\" $archive\n",
        );
        if (!$sent) {
            $this->ui->warn(
                sprintf(_('The simulator did not take the initialization of region %s.'), $plan->regionName),
            );

            return;
        }

        // The database of the simulator, as its config says (a region added to a simulator knows no more)
        $own = new Simulator($environment, $plan->iniPath());
        $db = new GridPlan();
        $db->dbHost = (string) ($own->databaseHost() ?? $plan->dbHost);
        $db->dbName = (string) ($own->databaseName() ?? $plan->dbName);
        $db->dbUser = (string) ($own->databaseUser() ?? $plan->dbUser);
        $db->dbPass = (string) ($own->databasePassword() ?? $plan->dbPass);
        $database = new DatabaseService($this->ui);
        $uuid = $plan->regionUuid;
        for ($try = 0; $try < 30; $try++) {
            $names = $database->select($db, "SELECT Name FROM land WHERE RegionUUID = '$uuid'") ?? [];
            if ($names !== [] && !in_array('Your Parcel', $names, true)) {
                $this->ui->note(
                    sprintf(_('Parcel of region %s is named %s.'), $plan->regionName, implode(', ', $names)),
                );

                return;
            }
            sleep(1);
        }
        $this->ui->warn(
            sprintf(
                _('The parcel of region %s is not named yet: its initialization object may still be starting.'),
                $plan->regionName,
            ),
        );
    }
    /**
     * The archive of the initialization object: the one the package has, else (a checkout of the
     * repository) made from its sources, which the simulator, running as another user, must read.
     */
    private function initArchive(): ?string
    {
        $source = null;
        foreach (ResourceRegistry::directories('share/ossl-scripts') as $dir) {
            if (is_file("$dir/fix-parcel-name.oar")) {
                return "$dir/fix-parcel-name.oar";
            }
            if ($source === null && is_file("$dir/fix-parcel-name-src/archive.xml")) {
                $source = "$dir/fix-parcel-name-src";
            }
        }
        if ($source === null) {
            return null;
        }
        defined('OPENSIM_ENGINE') || define('OPENSIM_ENGINE', true);
        $archive = sys_get_temp_dir() . '/opensim-kit-fix-parcel-name-' . getmypid() . '.oar';
        try {
            \OpenSim\Archives\Oar::pack($source, $archive);
        } catch (\RuntimeException $e) {
            $this->ui->warn($e->getMessage());

            return null;
        }
        chmod($archive, 0o644);

        return $archive;
    }
    /**
     * The owner of the estate gets the default region as home: Robust sets the home of an account
     * when it is made, and the default region did not exist yet for this one.
     */
    private function giveHome(SimulatorPlan $plan, Grid $grid): void
    {
        if ($grid->isRemote() || !in_array(Region::flagName(Region::DEFAULT_REGION), $plan->regionRoles, true)) {
            return;
        }
        $owner =
            $plan->estateOwner !== ''
                ? $plan->estateOwner
                : (string) ($grid->simulator($plan->slug)?->estateOwner() ?? '');
        if ($owner === '') {
            return;
        }
        $done = (new GridAccounts(new DatabaseService($this->ui), $this->ui))->setHome($grid, $owner, $plan->regionUuid);
        if ($done !== true) {
            $this->ui->warn(
                sprintf(
                    _('Could not set the home of %s: set it from the viewer, or the first login ends with an error.'),
                    $owner,
                ),
            );
        }
    }
    /** Whether the grid knows the region, waiting a little for it to tell. */
    private function registered(SimulatorPlan $plan, Grid $grid): bool
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/', $plan->regionName)) {
            return true; // cannot be looked for
        }
        $database = new DatabaseService($this->ui);
        for ($i = 0; $i < 60; $i++) {
            $rows = $database->select(
                $grid->databasePlan('GridService'),
                "SELECT 1 FROM regions WHERE regionName = '{$plan->regionName}'",
            );
            if ($rows === null) {
                return true; // the grid database cannot be read: not a reason to call it a failure
            }
            if ($rows !== []) {
                return true;
            }
            sleep(1);
        }

        return false;
    }
    /**
     * When the setup runs as root for an install with a system user (the
     * packages), what it creates belongs to that user, who runs the instances.
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
    /** Give the roles asked to the region in the Robust config of the grid. */
    private function giveRoles(SimulatorPlan $plan, Grid $grid): void
    {
        $ini = $grid->robustIni();
        if ($plan->regionRoles === [] || $grid->isRemote() || $ini === null) {
            return;
        }
        Region::giveRoles($ini, $plan->regionName, $plan->regionRoles);
        $this->ui->note(
            sprintf(_('Updated %s: %s is %s.'), $ini, $plan->regionName, implode(', ', $plan->regionRoles)),
        );
    }
    /**
     * Write the region, and load it in the simulator through its console, which does not restart
     * it. The simulator is started when it is not running.
     */
    private function applyRegion(SimulatorPlan $plan, Environment $environment, Grid $grid): void
    {
        @mkdir($plan->regionsDir(), 0o755, true);
        $region = (new SimulatorConfig())->writeRegion($plan);
        $this->ui->note(
            $region !== null
                ? sprintf(_('Wrote %s'), $region)
                : sprintf(_('Region %s already described, left as it is.'), $plan->regionName),
        );
        $this->giveToSystemUser($environment->layout->systemUser(), [$plan->regionsDir()], true);
        GridFileService::protect($plan->gridDir);
        $this->giveRoles($plan, $grid);
        if ($plan->overwriteRegion) {
            $this->needRestart($environment, $plan, 'changed');
        }
        if (!$plan->start) {
            return;
        }

        if (!InstanceConsoleService::running(Grid::at($environment, $plan->gridDir)->simulatorLink($plan->slug))) {
            $this->startSimulator($plan, $grid, $environment);

            return;
        }

        // Robust reads the roles of its regions when it starts
        if ($plan->regionRoles !== [] && !$grid->isRemote()) {
            $this->restartGrid($grid);
        }
        // The questions of a region with no estate are answered with their default (the estate it has)
        $file = basename($plan->regionIni());
        if (!InstanceConsoleService::send($plan->slug, "create region \"{$plan->regionName}\" $file\n\n\n")) {
            $this->failed(
                $plan,
                sprintf(
                    _(
                        "The simulator '%s' did not take the region %s through its console. It is described, and loaded the next time the simulator starts: opensim restart %s",
                    ),
                    $plan->simulatorName,
                    $plan->regionName,
                    $plan->slug,
                ),
            );
        }
        if (!$this->registered($plan, $grid)) {
            $this->failed(
                $plan,
                sprintf(_("Region %s did not register in the grid '%s'."), $plan->regionName, $grid->nick()),
            );
        }
        $this->giveHome($plan, $grid);
        $this->initRegion($plan, $environment);
        $this->ui->note(sprintf(_('Region %s is online.'), $plan->regionName));
    }
    /** Robust gives the roles to a region when it registers, from its config read when it starts. */
    private function restartGrid(Grid $grid): void
    {
        [$code] = InstanceControl::run($this->ui, 'restart', $grid->nick(), true);
        if ($code !== 0) {
            $this->ui->warn(
                sprintf(
                    _("The grid '%s' did not restart: try %s -v restart now %s"),
                    $grid->nick(),
                    'opensim',
                    $grid->nick(),
                ),
            );
        }
    }
    /** The simulator has to restart to take the region into account (its parcel is named then, see Actions). */
    private function needRestart(Environment $environment, SimulatorPlan $plan, string $why): void
    {
        PendingRestarts::add($environment, $plan->slug, "region {$plan->regionName} $why");
    }
}
