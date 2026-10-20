<?php

declare(strict_types=1);

/**
 * A standalone is a grid whose only simulator serves the grid itself: no Robust, the database of the grid is the one of the simulator,
 * no other simulator can join it. What the setup plans and writes for it.
 */

use OpenSim\Runtime\Outcome;
use OpenSim\Exceptions\InstanceFailed;
use OpenSim\Config\ConfigReader;
use OpenSim\Forms\FieldSet;
use OpenSim\Models\Grid;
use OpenSim\Models\Simulator;
use OpenSim\OpenSim;
use OpenSim\Provisioning\Features;
use OpenSim\Provisioning\GridConf;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\GridPlanner;
use OpenSim\Provisioning\GridServices;
use OpenSim\Provisioning\GridShared;
use OpenSim\Provisioning\SetupFile;
use OpenSim\Provisioning\SimulatorConfig;
use OpenSim\Provisioning\SimulatorMaster;
use OpenSim\Provisioning\Web\HelpersConfig;
use OpenSim\Provisioning\Web\Services;
use OpenSim\Runtime\GridMonitor;
use OpenSim\Testing\Machine;

/** The folder of a standalone grid beside a core that has the files of a standalone, and its description. */
function standalone_folder(bool $hypergrid = true): array
{
    $root = test_tmp() . '/standalone-' . bin2hex(random_bytes(4));
    $bin = "$root/core/bin";
    $dir = "$root/etc/grids/solo";
    mkdir("$bin/config-include", 0o755, true);
    mkdir($dir, 0o755, true);
    file_put_contents("$bin/OpenSimDefaults.ini", "[Startup]\n    Other = core\n");
    file_put_contents("$bin/OpenSim.ini.example", "[Startup]\n;Other = example\n");
    $architecture = $hypergrid ? 'StandaloneHypergrid.ini' : 'Standalone.ini';
    file_put_contents(
        "$bin/config-include/$architecture",
        "[Includes]\n    Include-Common = \"config-include/StandaloneCommon.ini\"\n",
    );
    file_put_contents(
        "$bin/config-include/StandaloneCommon.ini",
        <<<'INI'
        [Includes]
            Include-Storage = "config-include/storage/SQLiteStandalone.ini";
            Include-FlotsamCache = "config-include/FlotsamCache.ini"
        [Hypergrid]
            ; GatekeeperURI = "${Const|BaseURL}:${Const|PublicPort}"
        [LoginService]
            WelcomeMessage = "Welcome, Avatar!"
        [GridInfoService]
            gridname = "the lost continent of hippo"
            gridnick = "hippogrid"
        [UserProfilesService]
            Enabled = false
        INI
        . "\n",
    );
    file_put_contents("$bin/config-include/FlotsamCache.ini", "[AssetCache]\n    CacheDirectory = ./assetcache\n");
    file_put_contents("$bin/config-include/osslDefaultEnable.ini", "[OSSL]\n");
    file_put_contents("$bin/config-include/osslEnable.ini.example", "[OSSL]\n    AllowOSFunctions = false\n");
    file_put_contents(
        "$dir/solo.conf",
        "[Grid]\nGridName = Solo World\nGridNick = solo\nStandalone = true\nHypergrid = " .
            ($hypergrid ? 'true' : 'false') .
            "\nBaseHostname = solo.example.org\nWebUrl = https://www.example.org\nPublicPort = 9000\n",
    );

    return [$dir, $bin];
}

describe('The grid of a standalone', function () {
    test('is asked for, has no Robust, and answers on one port', function () {
        $v = planner()->resolve(['standalone' => true, 'public_port' => 9000]);
        $plan = planner()->plan(['standalone' => true, 'public_port' => 9000]);

        expect($v['standalone'])
            ->toBeTrue()
            ->and($plan->standalone)
            ->toBeTrue()
            ->and([$plan->publicPort, $plan->privatePort])
            ->toBe([9000, 9000])
            ->and(planner()->plan()->standalone)
            ->toBeFalse();
    });

    test('has its database named after its nick, not after a Robust it does not have', function () {
        expect(planner()->resolve(['standalone' => true])['database_name'])
            ->toBe('amy')
            ->and(planner()->resolve()['database_name'])
            ->toBe('amy_robust');
    });

    test('is described by its folder: its address, its port and its database', function () {
        $plan = planner()->plan([
            'standalone' => true,
            'public_port' => 9000,
            'database_name' => 'solo_db',
            'database_password' => 'secret',
        ]);
        $plan->etcDirectory = $plan->gridDir = test_tmp() . '/soloconf-' . bin2hex(random_bytes(4)) . '/amy';

        $conf = ConfigReader::file((new GridConf())->write($plan))['Grid'];

        expect($conf)->toMatchArray([
            'Standalone' => 'true',
            'PublicPort' => '9000',
            'DatabaseName' => 'solo_db',
            'DatabaseUser' => 'opensim',
            'DatabasePassword' => 'secret',
        ]);
    });
});

describe('The shared files of a standalone', function () {
    test('are those of a standalone, from the architecture to its common settings', function () {
        expect(GridShared::files(true, true))
            ->toContain('config-include/StandaloneHypergrid.ini', 'config-include/StandaloneCommon.ini')
            ->not->toContain('config-include/GridCommon.ini')
            ->and(GridShared::files(false, true))
            ->toContain('config-include/Standalone.ini')
            ->and(GridShared::files(true))
            ->toContain('config-include/GridHypergrid.ini', 'config-include/GridCommon.ini');
    });

    test('give the grid its own database, and read their includes where they are', function () {
        [$dir, $bin] = standalone_folder();

        (new GridShared())->merge($dir, $bin, true, standalone: true);
        (new GridShared())->prepare($dir, $bin, true, true);
        $text = file_get_contents("$dir/config-include/StandaloneCommon.ini");

        expect($text)
            ->toContain(';    Include-Storage = "config-include/storage/SQLiteStandalone.ini"')
            ->toContain('Include-FlotsamCache = "' . $dir . '/config-include/FlotsamCache.ini"')
            ->and(file_get_contents("$dir/config-include/StandaloneHypergrid.ini"))
            ->toContain('Include-Common = "' . $dir . '/config-include/StandaloneCommon.ini"');
    });
});

/** The plan of the services of a standalone over a folder made by standalone_folder(). */
function standalone_services_plan(string $dir, string $bin, array $set = []): GridPlan
{
    $plan = planner()->plan(['standalone' => true, 'name' => 'Solo World', 'nick' => 'solo']);
    $plan->etcDirectory = $plan->gridDir = $dir;
    $plan->binDir = $bin;
    $plan->webUrl = 'https://www.example.org';
    $plan->helpers = false;
    foreach ($set as $name => $value) {
        $plan->$name = $value;
    }

    return $plan;
}

describe('The services of a standalone', function () {
    test('tell the viewers its names, its helpers and its gatekeeper, in its own common settings', function () {
        [$dir, $bin] = standalone_folder();
        (new GridShared())->merge($dir, $bin, true, standalone: true);
        $plan = standalone_services_plan($dir, $bin, [
            'helpers' => true,
            'helpersPath' => '/helpers',
            'enableHypergrid' => true,
            'search' => 'internal',
        ]);

        $written = (new GridServices())->writeStandalone($plan);
        $ini = ConfigReader::load("$dir/config-include/StandaloneCommon.ini", expand: false);
        $pages = new Services('/helpers');

        expect($written)
            ->toBe("$dir/config-include/StandaloneCommon.ini")
            ->and($ini['GridInfoService'])
            ->toMatchArray([
                'gridname' => 'Solo World',
                'gridnick' => 'solo',
                'economy' => '${Const|WebURL}/helpers',
                'welcome' => '${Const|WebURL}' . $pages->path('welcome'),
                'search' => '${Const|WebURL}' . $pages->path('search'),
            ])
            ->and($ini['LoginService'])
            ->toMatchArray([
                'WelcomeMessage' => 'Welcome to Solo World, <USERNAME>!',
                'SearchURL' => '${Const|WebURL}' . $pages->path('search'),
            ])
            ->toHaveKeys(['DestinationGuide', 'MessageUrl'])
            ->and($ini['Hypergrid']['GatekeeperURI'])
            ->toBe('${Const|BaseURL}:${Const|PublicPort}')
            ->and($ini['UserProfilesService']['Enabled'])
            ->toBe('true');
    });

    test('say nothing of the helpers a grid without them has, nor of a hypergrid it is not', function () {
        [$dir, $bin] = standalone_folder(false);
        (new GridShared())->merge($dir, $bin, false, standalone: true);

        (new GridServices())->writeStandalone(standalone_services_plan($dir, $bin, ['enableHypergrid' => false]));
        $ini = ConfigReader::load("$dir/config-include/StandaloneCommon.ini", expand: false);

        expect($ini['GridInfoService'])
            ->not->toHaveKeys(['economy', 'search', 'web_profile_url'])
            ->and($ini['Hypergrid'] ?? [])
            ->not->toHaveKey('GatekeeperURI')
            ->and($ini['LoginService'])
            ->not->toHaveKey('MessageUrl');
    });

    test('keep what the operator set in the welcome message, and are written once', function () {
        [$dir, $bin] = standalone_folder();
        (new GridShared())->merge($dir, $bin, true, standalone: true);
        $plan = standalone_services_plan($dir, $bin, ['enableHypergrid' => true]);
        (new GridServices())->writeStandalone($plan);
        $common = "$dir/config-include/StandaloneCommon.ini";
        $text = str_replace('Welcome to Solo World, <USERNAME>!', 'Hello!', file_get_contents($common));
        file_put_contents($common, $text);

        $again = (new GridServices())->writeStandalone($plan);

        expect($again)
            ->toBeNull()
            ->and(file_get_contents($common))
            ->toContain('WelcomeMessage = "Hello!"');
    });

    test('are found by the helpers where a standalone has them', function () {
        [$dir, $bin] = standalone_folder();
        (new GridShared())->merge($dir, $bin, true, standalone: true);
        (new GridServices())->writeStandalone(
            standalone_services_plan($dir, $bin, ['helpers' => true, 'helpersPath' => '/helpers']),
        );
        (new SimulatorMaster())->ensure($dir, $bin);

        expect(HelpersConfig::webUrl($dir))
            ->toBe('https://www.example.org')
            ->and(HelpersConfig::basePath($dir))
            ->toBe('/helpers');
    });
});

describe('The features of a standalone', function () {
    /** The standalone machine of StandaloneTest, with the common settings of its folder. */
    function standalone_features(): array
    {
        $machine = standalone_machine(true);
        $common = $machine->etc . '/grids/solo/config-include/StandaloneCommon.ini';
        $machine->write(
            $common,
            "[UserProfilesService]\n    Enabled = false\n[GridInfoService]\n    gridname = \"x\"\n",
        );

        return [$machine, new Features(Grid::find($machine->environment, 'solo')), $common];
    }

    test('are set in its own common settings, where a grid sets them in Robust', function () {
        [, $features, $common] = standalone_features();

        $result = $features->enable(Features::PROFILES);

        expect($result['error'])
            ->toBeNull()
            ->and($result['robust'])
            ->toBeTrue()
            ->and(ConfigReader::file($common)['UserProfilesService']['Enabled'])
            ->toBe('true')
            ->and($features->disable(Features::PROFILES)['robust'])
            ->toBeTrue()
            ->and(ConfigReader::file($common)['UserProfilesService']['Enabled'])
            ->toBe('false');
    });

    test('keep the offline messages in the simulator, with the module V2 and no address', function () {
        [$machine, $features] = standalone_features();
        $ini = $machine->etc . '/grids/solo/sims/solo.ini';
        $machine->write(
            $ini,
            file_get_contents($ini) . "[Messaging]\n    OfflineMessageURL = \"https://x.example.org/offline.php\"\n",
        );

        $result = $features->enable(Features::OFFLINE, ['mode' => 'robust']);
        $messaging = ConfigReader::file($ini)['Messaging'];

        expect($result['error'])
            ->toBeNull()
            ->and($messaging['OfflineMessageModule'])
            ->toBe('Offline Message Module V2')
            ->and($messaging)
            ->not->toHaveKey('OfflineMessageURL');
    });
});

describe('The layer of a standalone for its simulator', function () {
    /** The lines the grid decides for a planned standalone. */
    function standalone_layer(array $set = []): string
    {
        $machine = new Machine();
        $plan = planner()->plan(['standalone' => true, 'name' => 'Solo World', 'nick' => 'solo'] + $set);
        $plan->webUrl = 'https://www.example.org';

        return implode("\n", SimulatorConfig::gridLayer(Grid::planned($machine->environment, $plan)));
    }

    test('has the offline module V2 with no address, and the groups served by the simulator', function () {
        $layer = standalone_layer(['offline' => 'robust', 'hypergrid' => true]);

        expect($layer)
            ->toContain('OfflineMessageModule = "Offline Message Module V2"')
            ->not->toContain('OfflineMessageURL')
            ->toContain('ServicesConnectorModule = "Groups HG Service Connector"')
            ->toContain('MessageOnlineUsersOnly = true')
            ->toContain('LocalService = local')
            ->not->toContain('GroupsServerURI')
            ->not->toContain('XBakes');
    });

    test('has the local connector of the groups when it is not a hypergrid', function () {
        expect(standalone_layer(['offline' => 'robust', 'hypergrid' => false, 'helpers' => true]))
            ->toContain('ServicesConnectorModule = "Groups Local Service Connector"')
            ->not->toContain('LocalService');
    });
});

describe('The questions about a standalone', function () {
    test('leave out what only a Robust has: a private port, a console of the grid', function () {
        $fields = new FieldSet(Grid::fields());

        expect($fields->visible('private_port', ['standalone' => false]))
            ->toBeTrue()
            ->and($fields->visible('private_port', ['standalone' => true]))
            ->toBeFalse()
            ->and($fields->visible('console', ['standalone' => true]))
            ->toBeFalse()
            ->and($fields->visible('console_port', ['standalone' => false, 'console' => 'rest']))
            ->toBeTrue()
            ->and($fields->visible('console_port', ['standalone' => true, 'console' => 'rest']))
            ->toBeFalse();
    });

    test('refuse the offline service of Robust that it does not have', function () {
        $planner = planner();

        expect($planner->problems(['standalone' => true, 'helpers' => true, 'offline' => 'both']))
            ->toHaveKey('offline')
            ->and($planner->problems(['standalone' => false, 'helpers' => true, 'offline' => 'both']))
            ->not->toHaveKey('offline')
            ->and($planner->problems(['standalone' => true, 'helpers' => true, 'offline' => 'robust']))
            ->not->toHaveKey('offline');
    });

    test('do not ask its simulator for the port and the database it has from the grid', function () {
        $fields = new FieldSet(Simulator::fields(['standalone' => true, 'accounts' => []]));
        $others = new FieldSet(Simulator::fields(['accounts' => []]));

        foreach (['http_port', 'database_host', 'database_name', 'database_user', 'database_password'] as $name) {
            expect($fields->visible($name, []))->toBeFalse()->and($others->visible($name, []))->toBeTrue();
        }
        expect($fields->visible('estate_name', []))->toBeTrue();
    });

    test('are refused a second simulator in a file', function () {
        $simulator = ['name' => 'Welcome', 'database' => ['user' => 'a', 'password' => 'b']];
        $data = [
            'grid' => ['name' => 'Solo', 'standalone' => true, 'database' => ['user' => 'a', 'password' => 'b']],
            'simulators' => [$simulator, ['name' => 'Second'] + $simulator],
        ];

        expect(SetupFile::problems($data))
            ->toContain('a standalone has one simulator (grid.standalone is true)')
            ->and(SetupFile::problems(['grid' => ['standalone' => false] + $data['grid']] + $data))
            ->not->toContain('a standalone has one simulator (grid.standalone is true)');
    });
});

describe('The master of a standalone', function () {
    test('includes the architecture of a standalone, with the address and the port of its description', function () {
        [$dir, $bin] = standalone_folder();

        $ini = ConfigReader::load((string) (new SimulatorMaster())->ensure($dir, $bin), expand: false);

        expect($ini['Architecture']['Include-Architecture'])
            ->toBe("$dir/config-include/StandaloneHypergrid.ini")
            ->and($ini['Const'])
            ->toMatchArray(['BaseHostname' => 'solo.example.org', 'PublicPort' => '9000']);
    });
});

describe('The config of the simulator of a standalone', function () {
    /** The plan of a simulator of a standalone whose owner has no account yet. */
    function standalone_simulator_plan(): \OpenSim\Provisioning\SimulatorPlan
    {
        $plan = new \OpenSim\Provisioning\SimulatorPlan();
        $plan->standalone = true;
        $plan->gridDir = test_tmp() . '/solosim-' . bin2hex(random_bytes(4));
        $plan->gridNick = 'solo';
        $plan->gridName = 'Solo World';
        $plan->slug = 'solo_welcome';
        $plan->simulatorName = 'Welcome';
        $plan->estateName = 'Solo Estate';
        $plan->estateOwner = 'Ada Love';
        $plan->createOwner = true;
        $plan->ownerEmail = 'ada@example.org';
        $plan->ownerPassword = 'secret123';
        $plan->ownerUuid = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
        mkdir($plan->gridDir, 0o755, true);

        return $plan;
    }

    test('has the account of its owner made by OpenSim at its first start, then forgets the password', function () {
        $plan = standalone_simulator_plan();
        $path = (new SimulatorConfig())->write($plan);

        expect(ConfigReader::file($path)['Estates'])->toMatchArray([
            'DefaultEstateOwnerName' => 'Ada Love',
            'DefaultEstateOwnerUUID' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
            'DefaultEstateOwnerEMail' => 'ada@example.org',
            'DefaultEstateOwnerPassword' => 'secret123',
        ]);

        (new SimulatorConfig())->forgetOwnerPassword($path);

        expect(ConfigReader::file($path)['Estates'])
            ->not->toHaveKey('DefaultEstateOwnerPassword')
            ->toHaveKey('DefaultEstateOwnerName');
    });

    test('includes the architecture of a standalone when it has no master', function () {
        $plan = standalone_simulator_plan();

        expect((new SimulatorConfig())->render($plan))->toContain(
            'Include-Architecture = "' . $plan->gridDir . '/config-include/StandaloneHypergrid.ini"',
        );
    });

    test('has no account to make when the owner has one', function () {
        $plan = standalone_simulator_plan();
        $plan->createOwner = false;

        expect((new SimulatorConfig())->render($plan))->not->toContain('DefaultEstateOwnerPassword');
    });
});

describe('The life of a standalone', function () {
    /** The standalone machine, its simulator running, and the region it describes. */
    function standalone_running(bool $enabled = true): Machine
    {
        $machine = standalone_machine(true, $enabled);
        $machine->write(
            $machine->etc . '/grids/solo/sims/solo/regions/Welcome.ini',
            "[Welcome]\nLocation = 1000,1000\n",
        );
        $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/solo.ini');
        $machine->consoles->console('solo')->onSend = fn(string $line) => $line === 'shutdown' &&
            $machine->processes->remove(4000);

        return $machine;
    }

    test('is stopped by the name of the grid, which names its simulator', function () {
        $machine = standalone_running();

        $results = OpenSim::stop('solo', true, $machine->environment);

        expect($results)
            ->toHaveCount(1)
            ->and(array_values($results)[0])
            ->toBe(Outcome::Stopped)
            ->and($machine->processes->all())
            ->toBe([]);
    });

    test('says when it has no simulator to run', function () {
        $machine = standalone_running(enabled: false);

        $results = OpenSim::start('solo', $machine->environment);

        expect($results)
            ->toHaveCount(1)
            ->and($results['solo'])
            ->toBeInstanceOf(InstanceFailed::class)
            ->and($results['solo']->getMessage())
            ->toContain('no simulator enabled');
    });

    test('has its simulator and its regions in the monitor, up while the simulator runs', function () {
        $machine = standalone_running();
        $grid = Grid::find($machine->environment, 'solo');

        $up = GridMonitor::of($grid);
        OpenSim::stop('solo', true, $machine->environment);
        $down = GridMonitor::of($grid);

        expect($up['simulators'][0])
            ->toMatchArray(['name' => 'solo', 'local' => true, 'running' => true, 'regions' => 1, 'online' => 1])
            ->and($up['regions'])
            ->toBe(['total' => 1, 'online' => 1])
            ->and($up['level'])
            ->toBe(GridMonitor::OK)
            ->and($down['regions'])
            ->toBe(['total' => 1, 'online' => 0])
            ->and($down['level'])
            ->toBe(GridMonitor::DEGRADED);
    });
});
