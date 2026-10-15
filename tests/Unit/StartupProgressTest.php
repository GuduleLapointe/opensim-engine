<?php

declare(strict_types=1);

/**
 * The steps of a simulator starting, read in its log.
 */

use OpenSim\Runtime\StartupProgress;

describe('steps of a start', function () {
    test('count the plugins of modules on the total the log announces', function () {
        $log = <<<'LOG'
            2026-10-01 20:51:08,005 INFO  [PLUGINS]: Plugin Loaded: OpenSim
            2026-10-01 20:51:08,006 INFO  [PLUGINS]: Plugin Loaded: OpenSim.ApplicationPlugins.RegionModulesController
            2026-10-01 20:51:08,007 INFO  [PLUGINS]: Plugin Loaded: OpenSim.ApplicationPlugins.RemoteController
            2026-10-01 20:51:08,011 INFO  [PLUGINS]: Plugin Loaded: LindenUDP
            2026-10-01 20:51:08,012 INFO  [PLUGINS]: Plugin Loaded: OpenSim.Region.CoreModules
            2026-10-01 20:51:08,100 INFO  [REGIONMODULES]: From plugin LindenUDP, (version 0.9.3.0), loaded 1 modules, 0 shared, 1 non-shared 0 unknown
            2026-10-01 20:51:08,101 INFO  [REGIONMODULES]: From plugin OpenSim.Region.CoreModules, (version 0.9.3.0), loaded 113 modules, 74 shared, 39 non-shared 0 unknown
            LOG;

        expect(StartupProgress::steps($log))->toBe(['  LindenUDP 1/2', '  OpenSim.Region.CoreModules 2/2']);
    });

    test('do not show the second report of the plugins', function () {
        $log = <<<'LOG'
            2026-10-01 20:51:08,010 INFO  [PLUGINS]: Plugin Loaded: OpenSim.ApplicationPlugins.RegionModulesController
            2026-10-01 20:51:08,011 INFO  [PLUGINS]: Plugin Loaded: LindenUDP
            2026-10-01 20:51:08,100 INFO  [REGIONMODULES]: From plugin LindenUDP, (version 0.9.3.0), loaded 1 modules, 0 shared, 1 non-shared 0 unknown
            2026-10-01 20:51:08,200 INFO  [REGIONMODULES]: Loading Region's modules
            2026-10-01 20:51:08,201 INFO  [REGIONMODULES]: From plugin LindenUDP, (version 0.9.3.0), loaded 1 modules, 0 shared, 1 non-shared 0 unknown
            LOG;

        expect(StartupProgress::steps($log))->toBe(['  LindenUDP 1/1']);
    });

    test('count the database up to its latest revision', function () {
        $log = <<<'LOG'
            2026-10-01 INFO  [MIGRATIONS]: Upgrading RegionStore to latest revision 66.
            2026-10-01 INFO  [MIGRATIONS]: Updating RegionStore to version 65
            2026-10-01 INFO  [MIGRATIONS]: Updating RegionStore to version 66
            LOG;

        expect(StartupProgress::steps($log))->toBe(['  Database RegionStore 65/66', '  Database RegionStore 66/66']);
    });

    test('take a region from its config to the grid, then ready', function () {
        $log = <<<'LOG'
            2026-10-01 INFO  [REGION LOADER FILE SYSTEM]: Loaded config for region Sim North
            2026-10-01 20:51:08,845 DEBUG [GRID SERVICE]: Region Sim North (607c44e9-3d01-45eb-a07e-937dc72dbadb, 256x256) registered at 1000,1001 with flags RegionOnline
            2026-10-01 20:51:09,100 DEBUG [RegionReady]: Region "Sim North" is ready: "OnlineDelay" on channel -800
            LOG;

        expect(StartupProgress::steps($log, 2))->toBe([
            '  Sim North: configured (1/2)',
            '  Sim North: registered in the grid (1/2)',
            '  Sim North: ready (1/2)',
        ]);
    });

    test('say nothing of other lines', function () {
        expect(StartupProgress::steps('2026-10-01 20:51:08,617 DEBUG [WORLD MAP]: Generating map image for Far'))->toBe([]);
    });

    test('show the first signs of life of the process as it says them', function () {
        $output = <<<'LOG'
            2026-10-01 20:51:03,100 INFO  [LOG4NET]: configured log4net using "/etc/opensim/OpenSim.exe.config"
            2026-10-01 20:51:03,200 INFO  [OPENSIM MAIN]: System Locale is en-US
            2026-10-01 20:51:03,300 INFO  [OPENSIM MAIN]: Environment is supported by OpenSimulator.
            2026-10-01 20:51:03,400 INFO  [OPENSIM MAIN]: Reading configuration settings
            2026-10-01 20:51:03,500 INFO  [OPENSIM MAIN]: STARTING OPENSIM
            2026-10-01 20:51:03,600 INFO  [OPENSIM MAIN]: Logging started to file /var/log/opensim/sim.log
            2026-10-01 20:51:03,700 INFO  [PLUGINS]: Plugin Loaded: LindenUDP
            LOG;

        expect(StartupProgress::steps($output, 1, true))->toBe([
            '  configured log4net using "/etc/opensim/OpenSim.exe.config"',
            '  System Locale is en-US',
            '  Environment is supported by OpenSimulator.',
            '  Reading configuration settings',
            '  STARTING OPENSIM',
            '  Logging started to file /var/log/opensim/sim.log',
        ]);
    });

    test('remove the control codes of a screen session', function () {
        expect(StartupProgress::steps("\033[1;32mSTARTING OPENSIM\033[0m\r", 1, true))->toBe(['  STARTING OPENSIM']);
    });

    test('show these signs from the log too, with the steps', function () {
        expect(StartupProgress::steps('2026-10-01 20:51:03,500 INFO  [OPENSIM MAIN]: STARTING OPENSIM'))->toBe(['  STARTING OPENSIM']);
    });
});
