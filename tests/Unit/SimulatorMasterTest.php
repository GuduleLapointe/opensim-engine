<?php

declare(strict_types=1);

/**
 * The master config of the simulators of a grid (OpenSimGridDefaults.ini, given with -inimaster): what it is made of, when it is
 * made again, that a simulator starts with it, and that a simulator with it says the same as one with a whole config.
 */

use OpenSim\Config\ConfigReader;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridOptions;
use OpenSim\Provisioning\SimulatorConfig;
use OpenSim\Provisioning\SimulatorPlan;
use OpenSim\Provisioning\SimulatorMaster;
use OpenSim\Runtime\Environment;
use OpenSim\Testing\Machine;
use OpenSim\Testing\EnvironmentFake;

/** A machine whose core has its defaults and whose grid has decided something in its OpenSim.ini. */
function master_machine(): Machine
{
    $machine = new Machine();
    $machine->write(
        $machine->core . '/bin/OpenSimDefaults.ini',
        "[Startup]\n    RegistryLocation = \"./registry\"\n    crash_dir = \"./crashes\"\n    Other = core\n[Network]\n    http_listener_port = 9000\n",
    );
    $machine->write(
        $machine->core . '/bin/OpenSim.ini.example',
        "[Search]\n;Module = OpenSimSearch\n[Startup]\n;Other = example\n",
    );
    $machine->write(
        $machine->grid . '/OpenSim.ini',
        "[Search]\n    Module = OpenSimSearch\n    SearchURL = \"https://web.example.org/helpers/query.php\"\n[Startup]\n;Other = example\n",
    );

    return $machine;
}

describe('master', function () {
    test(
        'is the defaults of the core, the model of the toolset, the grid and what the grid decided, in this order',
        function () {
            $machine = master_machine();
            $master = (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');

            expect($master)->toBe($machine->grid . '/' . SimulatorMaster::FILE);
            $ini = ConfigReader::load($master, expand: false);
            // The core, with what the model replaces
            expect($ini['Startup']['Other'])
                ->toBe('core')
                ->and($ini['Startup']['RegistryLocation'])
                ->toBe('${Const|DataDirectory}/registry')
                ->and($ini['Network']['http_listener_port'])
                ->toBe('9000')
                ->and($ini['DatabaseService']['StorageProvider'])
                ->toBe('OpenSim.Data.MySQL.dll')
                // The grid: those of its Robust, and its architecture
                ->and($ini['Const']['PublicPort'])
                ->toBe('8002')
                ->and($ini['Const']['BinDirectory'])
                ->toBe($machine->core . '/bin')
                ->and($ini['Const']['EtcDirectory'])
                ->toBe($machine->grid)
                ->and($ini['Architecture']['Include-Architecture'])
                ->toBe($machine->grid . '/config-include/GridHypergrid.ini')
                // What the grid decided
                ->and($ini['Search']['SearchURL'])
                ->toBe('https://web.example.org/helpers/query.php');
        },
    );

    test('is made again only when it differs', function () {
        $machine = master_machine();
        $bin = $machine->core . '/bin';
        $master = new SimulatorMaster();

        expect($master->ensure($machine->grid, $bin))
            ->not->toBeNull()
            ->and($master->ensure($machine->grid, $bin))
            ->toBeNull();

        $machine->write(
            $machine->grid . '/OpenSim.ini',
            "[Search]\n;Module = OpenSimSearch\n[Messaging]\n    OfflineMessageURL = \"https://web.example.org/helpers/offline.php\"\n",
        );
        expect($master->ensure($machine->grid, $bin))->not->toBeNull();
        $ini = ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE);
        expect($ini['Messaging']['OfflineMessageURL'])
            ->toBe('https://web.example.org/helpers/offline.php')
            ->and($ini['Search'] ?? [])
            ->not->toHaveKey('Module');
    });

    test('is only for the grids that have one: the others keep the whole config of their simulators', function () {
        $machine = master_machine();

        expect(SimulatorMaster::exists($machine->grid))
            ->toBeFalse()
            ->and(
                Grid::find($machine->environment, 'demo')
                    ->simulator('alpha')
                    ->master($machine->core . '/bin'),
            )
            ->toBeNull()
            ->and(file_exists($machine->grid . '/' . SimulatorMaster::FILE))
            ->toBeFalse();
    });
});

describe('A simulator with a master', function () {
    test('starts with it', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $machine->startLog('alpha', 'Region loaded');

        Grid::find($machine->environment, 'demo')->simulator('alpha')->start();

        $spec = $machine->launcher->launched[0];
        expect($spec->command)
            ->toContain('-inimaster=' . $machine->grid . '/' . SimulatorMaster::FILE)
            ->and($spec->command)
            ->toContain('-inifile=' . $machine->etc . '/opensim.d/alpha.ini');
    });

    test('makes the master again when the core changed', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $machine->write($machine->core . '/bin/OpenSimDefaults.ini', "[Startup]\n    Other = newer core\n");
        $machine->startLog('alpha', 'Region loaded');

        Grid::find($machine->environment, 'demo')->simulator('alpha')->start();

        expect(ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE)['Startup']['Other'])->toBe(
            'newer core',
        );
    });

    test('has the settings of the master and its own over them, and keeps the core its grid says', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $machine->write(
            $machine->grid . '/sims/alpha.ini',
            file_get_contents($machine->grid . '/sims/alpha.ini') .
                "\n[Const]\n    DataDirectory = \"{$machine->root}/data/alpha\"\n",
        );
        $simulator = Grid::find($machine->environment, 'demo')->simulator('alpha');

        expect($simulator->setting('Startup', 'Other'))
            ->toBe('core')
            ->and($simulator->setting('Network', 'http_listener_port'))
            ->toBe('9000')
            ->and($simulator->launchSetting('RegistryLocation'))
            ->toBe($machine->root . '/data/alpha/registry')
            ->and($simulator->core()->bin())
            ->toBe($machine->core . '/bin');
    });
});

/** A machine with a grid joined by a preset: its folder describes it, the folder of its preset holds what applies to its simulators. */
function preset_machine(): array
{
    $machine = master_machine();
    $grid = $machine->etc . '/grids/osg';
    $preset = $machine->etc . '/presets/osg';
    $machine->write(
        $grid . '/osg.conf',
        "[Grid]\nRemote = true\nGridName = \"OS Grid\"\nPreset = osg\nBaseHostname = login.osgrid.org\nPublicPort = 80\nPrivatePort = 80\nHypergrid = true\nCoreDirectory = {$machine->core}\n",
    );
    $machine->write(
        $preset . '/OpenSim.ini',
        "[Search]\n    Module = OpenSimSearch\n    SearchURL = \"https://osgrid.example.org/query.php\"\n[Startup]\n;Other = example\n",
    );
    $machine->write($preset . '/config-include/GridHypergrid.ini', "[Modules]\n    Test = true\n");

    return [$machine, $grid, $preset];
}

describe('A grid joined by a preset', function () {
    test(
        'has a master like a grid, made from its preset: the architecture, the OSSL and what the preset decided',
        function () {
            [$machine, $grid, $preset] = preset_machine();

            $master = (new SimulatorMaster())->ensure($grid, $machine->core . '/bin');

            expect($master)->toBe($grid . '/' . SimulatorMaster::FILE);
            $ini = ConfigReader::load($master, expand: false);
            expect($ini['Const']['BaseHostname'])
                ->toBe('login.osgrid.org')
                ->and($ini['Const']['PublicPort'])
                ->toBe('80')
                ->and($ini['Architecture']['Include-Architecture'])
                ->toBe($preset . '/config-include/GridHypergrid.ini')
                ->and($ini['OSSL']['Include-osslDefaultEnable'])
                ->toBe($preset . '/config-include/osslDefaultEnable.ini')
                ->and($ini['Search']['SearchURL'])
                ->toBe('https://osgrid.example.org/query.php');
        },
    );

    test('follows the preset: a change there is taken the next time the master is made', function () {
        [$machine, $grid, $preset] = preset_machine();
        $bin = $machine->core . '/bin';
        (new SimulatorMaster())->ensure($grid, $bin);

        $machine->write(
            $preset . '/OpenSim.ini',
            "[Search]\n;Module = OpenSimSearch\n[Economy]\n    economymodule = Gloebit\n",
        );

        expect((new SimulatorMaster())->ensure($grid, $bin))->not->toBeNull();
        $ini = ConfigReader::load($grid . '/' . SimulatorMaster::FILE);
        expect($ini['Economy']['economymodule'])
            ->toBe('Gloebit')
            ->and($ini['Search'] ?? [])
            ->not->toHaveKey('Module');
    });

    test('is a grid without a preset when its description names none', function () {
        $machine = master_machine();

        expect(SimulatorMaster::presetDir($machine->grid))->toBeNull();
        [, $grid, $preset] = preset_machine();
        expect(SimulatorMaster::presetDir($grid))->toBe($preset);
    });

    test('has the features enabled in its preset, for all its simulators', function () {
        [$machine, $grid, $preset] = preset_machine();
        $bin = $machine->core . '/bin';
        (new SimulatorMaster())->ensure($grid, $bin);
        $info = Grid::find(
            EnvironmentFake::with(['EtcRoot' => $machine->etc, 'CoreDirectory' => $machine->core]),
            'osg',
        );
        $options = new GridOptions($info);

        $result = $options->enable('economy-gloebit');

        expect($info->preset())
            ->toBe('osg')
            ->and($options->general())
            ->toBe($preset . '/OpenSim.ini')
            ->and($result['error'])
            ->toBeNull()
            ->and(ConfigReader::load($preset . '/OpenSim.ini')['Economy']['economymodule'])
            ->toBe('Gloebit')
            // The master follows
            ->and(ConfigReader::load($grid . '/' . SimulatorMaster::FILE)['Economy']['economymodule'])
            ->toBe('Gloebit');
    });
});

/** The plan of a simulator of a grid with helpers. */
function master_plan(string $gridDir, string $core): SimulatorPlan
{
    $plan = new SimulatorPlan();
    $plan->gridNick = 'demo';
    $plan->gridName = 'Demo World';
    $plan->gridDir = $gridDir;
    $plan->baseHostname = 'demo.example.org';
    $plan->slug = 'demo_sim1';
    $plan->simulatorName = 'Sim1';
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
    // What the options of the grid say for its simulators (see GridOptions::layer())
    $plan->optionsLayer = implode("\n", [
        '[Search]',
        'Module = OpenSimSearch',
        'SearchURL = "https://demo.example.org/helpers/query.php"',
        '[DataSnapshot]',
        'index_sims = true',
        'gridname = "Demo World"',
        'DATA_SRV_Demo = "https://demo.example.org/helpers/register.php"',
        '[Messaging]',
        'OfflineMessageModule = OfflineMessageModule',
        'OfflineMessageURL = "https://demo.example.org/helpers/offline.php"',
        '[UserProfiles]',
        'ProfileServiceURL = "${Const|BaseURL}:${Const|PublicPort}"',
    ]);

    return $plan;
}

describe('config of a simulator', function () {
    test('says only what makes it its own when its grid has a master', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $text = (new SimulatorConfig())->render(master_plan($machine->grid, $machine->core));

        expect($text)
            ->toContain(
                'http_listener_port = 9100',
                'DefaultEstateName = "Demo Estate"',
                'ConnectionString = ',
                'DataDirectory = "/var/lib/demo/sim1"',
            )
            ->and($text)
            ->not->toContain(
                '[Search]',
                '[Messaging]',
                '[UserProfiles]',
                '[Architecture]',
                '[OSSL]',
                'BaseHostname',
                'StorageProvider',
                'RegistryLocation',
            );
    });

    test('is whole when its grid has none', function () {
        $machine = master_machine();
        $text = (new SimulatorConfig())->render(master_plan($machine->grid, $machine->core));

        expect($text)->toContain(
            '[Search]',
            '[Messaging]',
            '[UserProfiles]',
            '[Architecture]',
            'BaseHostname = "demo.example.org"',
            'StorageProvider',
        );
    });

    test('with the master says the same as the whole one, for everything the whole one says', function () {
        // The grid has decided what the whole config of the simulator says of it (the helpers, from the same address)
        $machine = master_machine();
        $plan = master_plan($machine->grid, $machine->core);
        $whole = (new SimulatorConfig())->render($plan);
        $general = new OpenSim\Config\IniEditor(OpenSim\Config\TextFileService::read($machine->grid . '/OpenSim.ini'));
        $general->overlay($plan->optionsLayer);
        $general->save($machine->grid . '/OpenSim.ini');
        // The grid is the one the plan is for: the host and the ports of its Robust
        $machine->write(
            $machine->grid . '/Robust.HG.ini',
            file_get_contents($machine->grid . '/Robust.HG.ini') . "\n[Constants]\n",
        );
        $robust = OpenSim\Config\IniEditor::load($machine->grid . '/Robust.HG.ini');
        $robust->set('Const', 'BaseHostname', '"demo.example.org"');
        $robust->save($machine->grid . '/Robust.HG.ini');
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $machine->write($machine->grid . '/sims/lean.ini', (new SimulatorConfig())->render($plan));

        $expected = ConfigReader::load($machine->write($machine->root . '/whole.ini', $whole));
        $actual = ConfigReader::load([$machine->grid . '/' . SimulatorMaster::FILE, $machine->grid . '/sims/lean.ini']);

        $differences = [];
        foreach ($expected as $section => $values) {
            foreach ($values as $key => $value) {
                if (($actual[$section][$key] ?? null) !== $value) {
                    $differences[] =
                        "$section/$key: " .
                        var_export($value, true) .
                        ' / ' .
                        var_export($actual[$section][$key] ?? null, true);
                }
            }
        }
        expect($differences)->toBe([]);
    });
});

describe('options of a grid with a master', function () {
    function master_options(Machine $machine): GridOptions
    {
        return new GridOptions(
            Grid::find(EnvironmentFake::with(['EtcRoot' => $machine->etc, 'CoreDirectory' => $machine->core]), 'demo'),
        );
    }

    test('are decided in the OpenSim.ini of the grid, and the master follows', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $before = file_get_contents($machine->grid . '/sims/alpha.ini');

        $result = master_options($machine)->enable('economy-beta');

        expect($result['error'])
            ->toBeNull()
            ->and($result['grid'])
            ->toBeTrue()
            ->and($result['simulators'])
            ->toBe([])
            ->and(file_get_contents($machine->grid . '/sims/alpha.ini'))
            ->toBe($before)
            ->and(ConfigReader::load($machine->grid . '/OpenSim.ini')['Economy']['economymodule'])
            ->toBe('BetaGridLikeMoneyModule')
            ->and(ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE)['Economy']['economymodule'])
            ->toBe('BetaGridLikeMoneyModule');

        $result = master_options($machine)->disable('economy-beta');
        expect(ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE)['Economy'] ?? [])->not->toHaveKey(
            'economymodule',
        );
    });

    test('still change the setting a simulator says itself', function () {
        $machine = master_machine();
        (new SimulatorMaster())->ensure($machine->grid, $machine->core . '/bin');
        $machine->write(
            $machine->grid . '/sims/alpha.ini',
            file_get_contents($machine->grid . '/sims/alpha.ini') . "\n[Economy]\n    economymodule = Gloebit\n",
        );

        $result = master_options($machine)->enable('economy-beta');

        expect($result['simulators'])
            ->toBe(['alpha'])
            ->and(ConfigReader::load($machine->grid . '/sims/alpha.ini')['Economy']['economymodule'])
            ->toBe('BetaGridLikeMoneyModule');
    });

    test('are in the simulators when the grid has no master', function () {
        $machine = master_machine();

        $result = master_options($machine)->enable('economy-beta');

        expect($result['simulators'])
            ->toBe(['alpha', 'beta'])
            ->and(file_exists($machine->grid . '/' . SimulatorMaster::FILE))
            ->toBeFalse();
    });
});

describe('grid made by the setup', function () {
    /** Run the part of the provisioning that makes the master, for a grid with helpers in a folder. */
    function master_provision(Machine $machine, bool $helpers = true): void
    {
        $ui = new class implements OpenSim\Provisioning\UiInterface {
            public function intro(string $title): void {}
            public function note(string $message): void {}
            public function entity(string $name): string
            {
                return $name;
            }
            public function warn(string $message): void {}
            public function error(string $message): void {}
            public function outro(string $message): void {}
            public function choose(string $label, array $options, ?string $default = null, ?string $hint = null): string
            {
                return $default ?? '';
            }
            public function checklist(string $label, array $options, array $defaults = [], ?string $hint = null): array
            {
                return $defaults;
            }
            public function text(
                string $label,
                string $default = '',
                ?\Closure $validate = null,
                ?string $hint = null,
            ): string {
                return $default;
            }
            public function secret(string $label, ?\Closure $validate = null, ?string $hint = null): string
            {
                return '';
            }
            public function form(array $fields, string $title = ''): array
            {
                return [];
            }
            public function confirm(string $label, bool $default = true): bool
            {
                return $default;
            }
            public function spin(\Closure $callback, string $message): mixed
            {
                return $callback();
            }
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

        $environment = EnvironmentFake::with(['EtcRoot' => $machine->etc, 'CoreDirectory' => $machine->core]);
        // The options of a grid that is made: the ones that are on by default and that it can have
        $plan->options = (new GridOptions(Grid::planned($environment, $plan)))->selection(['offline' => 'helpers']);

        $provisioner = new OpenSim\Provisioning\GridProvisioner($ui);
        $options = new ReflectionMethod($provisioner, 'applyOptions');
        $options->invoke($provisioner, $plan, $environment);
        $master = new ReflectionMethod($provisioner, 'writeMaster');
        $master->invoke($provisioner, $plan);
    }

    test(
        'has what it decides for its simulators in its OpenSim.ini, quoted as the files of OpenSimulator are, and in its master',
        function () {
            $machine = master_machine();
            $machine->write(
                $machine->grid . '/OpenSim.ini',
                "[Search]\n;Module = OpenSimSearch\n;SearchURL = \"x\"\n[DataSnapshot]\n;index_sims = true\n[Messaging]\n;OfflineMessageURL = \"u\"\n",
            );

            master_provision($machine);

            $text = file_get_contents($machine->grid . '/OpenSim.ini');
            expect($text)->toContain(
                'SearchURL = "https://demo.example.org/helpers/query.php"',
                'OfflineMessageURL = "https://demo.example.org/helpers/offline.php"',
                'DATA_SRV_Demo = "https://demo.example.org/helpers/register.php"',
                'gridname = "Demo World"',
                'index_sims = true',
            );
            $master = ConfigReader::load($machine->grid . '/' . SimulatorMaster::FILE);
            expect($master['Search']['SearchURL'])
                ->toBe('https://demo.example.org/helpers/query.php')
                ->and($master['DataSnapshot']['index_sims'])
                ->toBe('true')
                ->and($master['DataSnapshot']['DATA_SRV_Demo'])
                ->toBe('https://demo.example.org/helpers/register.php')
                ->and($master['Messaging']['OfflineMessageURL'])
                ->toBe('https://demo.example.org/helpers/offline.php')
                ->and($master['UserProfiles']['ProfileServiceURL'])
                ->toBe('http://localhost:8002');
        },
    );
});

describe('offline messages of a grid', function () {
    test('are enabled in the simulators with the module V1 and the helpers, or the module V2 and Robust', function () {
        $machine = master_machine();
        $options = master_options($machine);

        $helpers = $options->enable('offline-helpers', ['prefix' => 'https://web.example.org/helpers']);
        $ini = ConfigReader::load($machine->grid . '/sims/alpha.ini', expand: false);
        $robust = $options->enable('offline-robust');
        $v2 = ConfigReader::load($machine->grid . '/sims/alpha.ini', expand: false);

        expect($helpers['error'])
            ->toBeNull()
            ->and($helpers['simulators'])
            ->toBe(['alpha', 'beta'])
            ->and($ini['Messaging'])
            ->toBe([
                'OfflineMessageModule' => 'OfflineMessageModule',
                'OfflineMessageURL' => 'https://web.example.org/helpers/offline.php',
            ])
            ->and($robust['error'])
            ->toBeNull()
            ->and($v2['Messaging'])
            ->toBe([
                'OfflineMessageModule' => 'Offline Message Module V2',
                'OfflineMessageURL' => '${Const|PrivURL}:${Const|PrivatePort}',
            ]);

        $options->disable('offline-robust');
        expect(ConfigReader::load($machine->grid . '/sims/alpha.ini', expand: false)['Messaging'] ?? [])->toBe([]);
    });

    test('need the helpers for the module V1, and an option that exists', function () {
        $options = master_options(master_machine());

        expect($options->enable('offline-helpers')['error'])
            ->toContain('has no helpers')
            ->toContain('offline-robust')
            ->and($options->enable('offline-v3')['error'])
            ->toContain("unknown option 'offline-v3'")
            ->and($options->resolve('offline', 'robust')?->name())
            ->toBe('offline-robust')
            ->and($options->resolve('offline', 'v3'))
            ->toBeNull();
    });

    test('are written in the config of a simulator for the module the grid uses', function () {
        $machine = master_machine();
        $plan = new OpenSim\Provisioning\GridPlan();
        $plan->gridNick = 'demo';
        $plan->gridName = 'Demo World';
        $plan->gridDir = $plan->etcDirectory = $machine->grid;
        $plan->helpers = true;
        $plan->webUrl = 'https://demo.example.org';
        $plan->helpersPath = '/helpers';
        $render = static function (array $options) use ($machine, $plan): string {
            $plan->options = $options;

            return (new GridOptions(Grid::planned(EnvironmentFake::with(['EtcRoot' => $machine->etc]), $plan)))->layer();
        };

        $helpers = $render(['offline-helpers']);
        $robust = $render(['offline-robust']);
        $none = $render(['profiles']);

        expect($helpers)
            ->toContain(
                'OfflineMessageModule = OfflineMessageModule',
                'OfflineMessageURL = "https://demo.example.org/helpers/offline.php"',
            )
            ->and($robust)
            ->toContain(
                'OfflineMessageModule = "Offline Message Module V2"',
                'OfflineMessageURL = "${Const|PrivURL}:${Const|PrivatePort}"',
            )
            ->not->toContain('offline.php')
            ->and($none)
            ->not->toContain('[Messaging]');
    });

    test('are the module a grid says, the helpers\' when it says none', function () {
        $machine = new Machine();
        $grid = fn() => Grid::find($machine->environment, 'demo');
        expect($grid()->offlineMode())->toBe('')->and($grid()->offlineModule())->toBe('');

        $machine->write(
            $machine->grid . '/demo.conf',
            "[Grid]\nGridName = Demo\nCoreDirectory = {$machine->core}\nOfflineMessages = robust\n",
        );

        expect($grid()->offlineMode())->toBe('robust')->and($grid()->offlineModule())->toBe('robust');
    });
});
