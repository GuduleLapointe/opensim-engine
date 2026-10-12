<?php

declare(strict_types=1);

/**
 * The master config of the simulators of a grid (OpenSimGridDefaults.ini, given with -inimaster): what it is made of, when it is
 * made again, that a simulator starts with it, and that a simulator with it says the same as one with a whole config.
 */

use OpenSim\Config\ConfigReader;
use OpenSim\Models\Grid;
use OpenSim\Models\GridInfo;
use OpenSim\Provisioning\Features;
use OpenSim\Provisioning\SimConfig;
use OpenSim\Provisioning\SimPlan;
use OpenSim\Provisioning\SimulatorMaster;
use OpenSim\Testing\Machine;

/** A machine whose core has its defaults and whose grid has decided something in its OpenSim.ini. */
function master_machine(): Machine
{
    $machine = new Machine();
    $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    RegistryLocation = \"./registry\"\n    crash_dir = \"./crashes\"\n    Other = core\n[Network]\n    http_listener_port = 9000\n");
    $machine->write($machine->core . '/bin/OpenSim.ini.example', "[Search]\n;Module = OpenSimSearch\n[Startup]\n;Other = example\n");
    $machine->write($machine->grid . '/OpenSim.ini', "[Search]\n    Module = OpenSimSearch\n    SearchURL = \"https://web.example.org/helpers/query.php\"\n[Startup]\n;Other = example\n");

    return $machine;
}

describe('The master', function () {
    test('is the defaults of the core, the model of the toolset, the grid and what the grid decided, in this order', function () {
        $machine = master_machine();
        $master = (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');

        expect($master)->toBe($machine->grid . '/' . SimulatorMaster::FILE);
        $ini = ConfigReader::load($master, expand: false);
        // The core, with what the model replaces
        expect($ini['Startup']['Other'])->toBe('core')
            ->and($ini['Startup']['RegistryLocation'])->toBe('${Const|DataDirectory}/registry')
            ->and($ini['Network']['http_listener_port'])->toBe('9000')
            ->and($ini['DatabaseService']['StorageProvider'])->toBe('OpenSim.Data.MySQL.dll')
            // The grid: those of its Robust, and its architecture
            ->and($ini['Const']['PublicPort'])->toBe('8002')
            ->and($ini['Const']['BinDirectory'])->toBe($machine->core . '/bin')
            ->and($ini['Const']['EtcDirectory'])->toBe($machine->grid)
            ->and($ini['Architecture']['Include-Architecture'])->toBe($machine->grid . '/config-include/GridHypergrid.ini')
            // What the grid decided
            ->and($ini['Search']['SearchURL'])->toBe('https://web.example.org/helpers/query.php');
    });

    test('is made again only when it differs', function () {
        $machine = master_machine();
        $bin = $machine->core . '/bin';
        $master = new SimulatorMaster();

        expect($master->ensure($machine->grid, $bin))->not->toBeNull()
            ->and($master->ensure($machine->grid, $bin))->toBeNull();

        $machine->write($machine->grid . '/OpenSim.ini', "[Search]\n;Module = OpenSimSearch\n[Messaging]\n    OfflineMessageURL = \"https://web.example.org/helpers/offline.php\"\n");
        expect($master->ensure($machine->grid, $bin))->not->toBeNull();
        $ini = ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE);
        expect($ini['Messaging']['OfflineMessageURL'])->toBe('https://web.example.org/helpers/offline.php')
            ->and($ini['Search'] ?? [])->not->toHaveKey('Module');
    });

    test('is only for the grids that have one: the others keep the whole config of their simulators', function () {
        $machine = master_machine();

        expect(SimulatorMaster::exists($machine->grid))->toBeFalse()
            ->and(Grid::find($machine->environment, 'demo')->simulator('alpha')->master($machine->core . '/bin'))->toBeNull()
            ->and(file_exists($machine->grid . '/' . SimulatorMaster::FILE))->toBeFalse();
    });
});

describe('A simulator with a master', function () {
    test('starts with it', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $machine->startLog('alpha', 'Region loaded');

        Grid::find($machine->environment, 'demo')->simulator('alpha')->start();

        $spec = $machine->launcher->launched[0];
        expect($spec->command)->toContain('-inimaster=' . $machine->grid . '/' . SimulatorMaster::FILE)
            ->and($spec->command)->toContain('-inifile=' . $machine->etc . '/opensim.d/alpha.ini');
    });

    test('makes the master again when the core changed', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    Other = newer core\n");
        $machine->startLog('alpha', 'Region loaded');

        Grid::find($machine->environment, 'demo')->simulator('alpha')->start();

        expect(ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE)['Startup']['Other'])->toBe('newer core');
    });

    test('has the settings of the master and its own over them, and keeps the core its grid says', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $machine->write($machine->grid . '/sims/alpha.ini', file_get_contents($machine->grid . '/sims/alpha.ini') . "\n[Const]\n    DataDirectory = \"{$machine->root}/data/alpha\"\n");
        $simulator = Grid::find($machine->environment, 'demo')->simulator('alpha');

        expect($simulator->setting('Startup', 'Other'))->toBe('core')
            ->and($simulator->setting('Network', 'http_listener_port'))->toBe('9000')
            ->and($simulator->launchSetting('RegistryLocation'))->toBe($machine->root . '/data/alpha/registry')
            ->and($simulator->core()->bin())->toBe($machine->core . '/bin');
    });
});

/** The plan of a simulator of a grid with helpers. */
function master_plan(string $gridDir, string $core): SimPlan
{
    $plan = new SimPlan();
    $plan->gridNick = 'demo';
    $plan->gridName = 'Demo World';
    $plan->gridDir = $gridDir;
    $plan->baseHostname = 'demo.example.org';
    $plan->slug = 'demo_sim1';
    $plan->simName = 'Sim1';
    $plan->coreDirectory = $core;
    $plan->dataDirectory = '/var/lib/demo/sim1';
    $plan->cacheDirectory = '/var/cache/demo/sim1';
    $plan->logsDirectory = '/var/log/demo';
    $plan->httpPort = 9100;
    $plan->consolePort = 9104;
    $plan->consoleUser = 'user';
    $plan->consolePass = 'pass';
    $plan->dbName = 'demo_sim1';
    $plan->dbPass = 'secret';
    $plan->estateName = 'Demo Estate';
    $plan->estateOwner = 'Demo Owner';
    $plan->searchUrl = 'https://demo.example.org/helpers/query.php';
    $plan->registerUrl = 'https://demo.example.org/helpers/register.php';
    $plan->offlineUrl = 'https://demo.example.org/helpers/offline.php';

    return $plan;
}

describe('The config of a simulator', function () {
    test('says only what makes it its own when its grid has a master', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $text = (new SimConfig())->render(master_plan($machine->grid, $machine->core));

        expect($text)->toContain('http_listener_port = 9100', 'DefaultEstateName = "Demo Estate"', 'ConnectionString = ', 'DataDirectory = "/var/lib/demo/sim1"')
            ->and($text)->not->toContain('[Search]', '[Messaging]', '[UserProfiles]', '[Architecture]', '[OSSL]', 'BaseHostname', 'StorageProvider', 'RegistryLocation');
    });

    test('is whole when its grid has none', function () {
        $machine = master_machine();
        $text = (new SimConfig())->render(master_plan($machine->grid, $machine->core));

        expect($text)->toContain('[Search]', '[Messaging]', '[UserProfiles]', '[Architecture]', 'BaseHostname = "demo.example.org"', 'StorageProvider');
    });

    test('with the master says the same as the whole one, for everything the whole one says', function () {
        // The grid has decided what the whole config of the simulator says of it (the helpers, from the same address)
        $machine = master_machine();
        $plan = master_plan($machine->grid, $machine->core);
        $whole = (new SimConfig())->render($plan);
        $general = new OpenSim\Config\IniEditor(OpenSim\Config\TextFileService::read($machine->grid . '/OpenSim.ini'));
        $general->overlay(implode("\n", SimConfig::gridLayer($plan)));
        $general->save($machine->grid . '/OpenSim.ini');
        // The grid is the one the plan is for: the host and the ports of its Robust
        $machine->write($machine->grid . '/Robust.HG.ini', file_get_contents($machine->grid . '/Robust.HG.ini') . "\n[Constants]\n");
        $robust = OpenSim\Config\IniEditor::load($machine->grid . '/Robust.HG.ini');
        $robust->set('Const', 'BaseHostname', '"demo.example.org"');
        $robust->save($machine->grid . '/Robust.HG.ini');
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $machine->write($machine->grid . '/sims/lean.ini', (new SimConfig())->render($plan));

        $expected = ConfigReader::load($machine->write($machine->root . '/whole.ini', $whole));
        $actual = ConfigReader::load([$machine->grid . '/' . SimulatorMaster::FILE, $machine->grid . '/sims/lean.ini']);

        $differences = [];
        foreach ($expected as $section => $values) {
            foreach ($values as $key => $value) {
                if (($actual[$section][$key] ?? null) !== $value) {
                    $differences[] = "$section/$key: " . var_export($value, true) . ' / ' . var_export($actual[$section][$key] ?? null, true);
                }
            }
        }
        expect($differences)->toBe([]);
    });
});

describe('The features of a grid with a master', function () {
    function master_features(Machine $machine): Features
    {
        return new Features(GridInfo::load(['EtcRoot' => $machine->etc, 'CoreDirectory' => $machine->core], 'demo'));
    }

    test('are decided in the OpenSim.ini of the grid, and the master follows', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $before = file_get_contents($machine->grid . '/sims/alpha.ini');

        $result = master_features($machine)->enable('economy', ['money' => 'beta']);

        expect($result['error'])->toBeNull()
            ->and($result['grid'])->toBeTrue()
            ->and($result['sims'])->toBe([])
            ->and(file_get_contents($machine->grid . '/sims/alpha.ini'))->toBe($before)
            ->and(ConfigReader::load($machine->grid . '/OpenSim.ini')['Economy']['economymodule'])->toBe('BetaGridLikeMoneyModule')
            ->and(ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE)['Economy']['economymodule'])->toBe('BetaGridLikeMoneyModule');

        $result = master_features($machine)->disable('economy');
        expect(ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE)['Economy'] ?? [])->not->toHaveKey('economymodule');
    });

    test('still change the setting a simulator says itself', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $machine->write($machine->grid . '/sims/alpha.ini', file_get_contents($machine->grid . '/sims/alpha.ini') . "\n[Economy]\n    economymodule = Gloebit\n");

        $result = master_features($machine)->enable('economy', ['money' => 'beta']);

        expect($result['sims'])->toBe(['alpha'])
            ->and(ConfigReader::load($machine->grid . '/sims/alpha.ini')['Economy']['economymodule'])->toBe('BetaGridLikeMoneyModule');
    });

    test('are in the simulators when the grid has no master', function () {
        $machine = master_machine();

        $result = master_features($machine)->enable('economy', ['money' => 'beta']);

        expect($result['sims'])->toBe(['alpha', 'beta'])
            ->and(file_exists($machine->grid . '/' . SimulatorMaster::FILE))->toBeFalse();
    });
});

describe('The grid made by the setup', function () {
    /** Run the part of the provisioning that makes the master, for a grid with helpers in a folder. */
    function master_provision(Machine $machine, bool $helpers = true): void
    {
        $ui = new class implements OpenSim\Provisioning\UiInterface {
            public function intro(string $title): void {}
            public function note(string $message): void {}
            public function entity(string $name): string { return $name; }
            public function warn(string $message): void {}
            public function error(string $message): void {}
            public function outro(string $message): void {}
            public function choose(string $label, array $options, ?string $default = null, ?string $hint = null): string { return $default ?? ''; }
            public function checklist(string $label, array $options, array $defaults = [], ?string $hint = null): array { return $defaults; }
            public function text(string $label, string $default = '', ?\Closure $validate = null, ?string $hint = null): string { return $default; }
            public function secret(string $label, ?\Closure $validate = null, ?string $hint = null): string { return ''; }
            public function form(array $fields, string $title = ''): array { return []; }
            public function confirm(string $label, bool $default = true): bool { return $default; }
            public function spin(\Closure $callback, string $message): mixed { return $callback(); }
        };
        $plan = new OpenSim\Provisioning\GridPlan();
        $plan->gridNick = 'demo';
        $plan->gridName = 'Demo World';
        $plan->gridDir = $plan->etcDirectory = $machine->grid;
        $plan->binDir = $machine->core . '/bin';
        $plan->enableHypergrid = true;
        $plan->helpers = $helpers;
        $plan->webUrl = 'https://demo.example.org';
        $plan->helpersPath = '/helpers';

        $method = new ReflectionMethod(OpenSim\Provisioning\GridProvisioner::class, 'writeMaster');
        $method->invoke(new OpenSim\Provisioning\GridProvisioner($ui), $plan);
    }

    test('has what it decides for its simulators in its OpenSim.ini, quoted as the files of OpenSimulator are, and in its master', function () {
        $machine = master_machine();
        $machine->write($machine->grid . '/OpenSim.ini', "[Search]\n;Module = OpenSimSearch\n;SearchURL = \"x\"\n[DataSnapshot]\n;index_sims = true\n[Messaging]\n;OfflineMessageURL = \"u\"\n");

        master_provision($machine);

        $text = file_get_contents($machine->grid . '/OpenSim.ini');
        expect($text)->toContain('SearchURL = "https://demo.example.org/helpers/query.php"', 'OfflineMessageURL = "https://demo.example.org/helpers/offline.php"', 'DATA_SRV_Demo = "https://demo.example.org/helpers/register.php"', 'gridname = "Demo World"', 'index_sims = true');
        $master = ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE);
        expect($master['Search']['SearchURL'])->toBe('https://demo.example.org/helpers/query.php')
            ->and($master['DataSnapshot']['index_sims'])->toBe('true')
            ->and($master['DataSnapshot']['DATA_SRV_Demo'])->toBe('https://demo.example.org/helpers/register.php')
            ->and($master['Messaging']['OfflineMessageURL'])->toBe('https://demo.example.org/helpers/offline.php')
            ->and($master['UserProfiles']['ProfileServiceURL'])->toBe('http://localhost:8002');
    });

    test('keeps a feature the operator disabled after it was made', function () {
        $machine = master_machine();
        master_provision($machine);
        (new Features(GridInfo::load(['EtcRoot' => $machine->etc, 'CoreDirectory' => $machine->core], 'demo')))->disable('search');

        master_provision($machine);

        expect(file_get_contents($machine->grid . '/OpenSim.ini'))->toContain(';SearchURL = "https://demo.example.org/helpers/query.php"');
        expect(ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE)['Search'] ?? [])->not->toHaveKey('SearchURL');
    });
});
