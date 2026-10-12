<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Provisioning\SimulatorMaster;

/**
 * A simulator: one process that runs one or several regions of a grid.
 */
final class Simulator extends Instance
{
    public function type(): string
    {
        return 'opensim';
    }

    public function assembly(): string
    {
        return 'OpenSim';
    }

    public static function enabledFolder(): string
    {
        return 'opensim.d';
    }

    protected function consolePortKeys(): array
    {
        return ['console_port', 'consoleport'];
    }

    protected function configFiles(): array
    {
        $grid = $this->gridDirectory();

        return $grid !== null && SimulatorMaster::exists($grid) ? [SimulatorMaster::path($grid), $this->ini()] : [$this->ini()];
    }

    /**
     * The master of its grid, made again when the core or the grid changed, null when the grid has none (its simulators have
     * their whole config).
     */
    public function master(string $binDir): ?string
    {
        $grid = $this->gridDirectory();
        if ($grid === null || !SimulatorMaster::exists($grid)) {
            return null;
        }
        (new SimulatorMaster())->ensure($grid, $binDir);
        $this->forget();

        return SimulatorMaster::path($grid);
    }

    /** The folder of the descriptions of its regions, empty when its config does not say where they are. */
    public function regionsDirectory(): string
    {
        return rtrim($this->launchSetting('regionload_regionsdir'), '/');
    }

    /** Whether it has a region to load: a region file in its regions folder. One without waits for the answers of a region to create. */
    public function hasRegion(): bool
    {
        $dir = $this->regionsDirectory();

        // A simulator that does not say where its regions are (one made by hand) is left alone
        return $dir === '' || glob("$dir/*.ini") !== [];
    }
}
