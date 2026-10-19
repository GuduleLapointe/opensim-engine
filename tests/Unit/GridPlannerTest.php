<?php

declare(strict_types=1);

/**
 * From the answers about a grid to its plan: what is proposed, what follows what was changed, a grid that is changed, what is wrong.
 */

use OpenSim\Models\Grid;
use OpenSim\Controllers\RobustController;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\GridPlanner;
use OpenSim\Runtime\Environment;
use OpenSim\Testing\Machine;
use OpenSim\Testing\EnvironmentFake;

/** A planner for a machine called amy, with random things that are not: a counter. */
function planner(array $more = []): GridPlanner
{
    $counter = 0;

    return GridPlanner::with(
        $more + [
            'environment' => EnvironmentFake::with([
                'EtcRoot' => '/etc/opensim',
                'DataRoot' => '/var/lib/opensim/data',
                'CacheRoot' => '/var/cache/opensim',
                'LogsRoot' => '/var/log/opensim',
                'CoreDirectory' => '/usr/share/opensim/0.9.3.0',
            ]),
            'cores' => ['/usr/share/opensim/0.9.3.0' => '0.9.3.0'],
            'defaultName' => 'Amy',
            'defaultHost' => 'amy.example.org',
            'defaultPublic' => 8002,
            'nextPort' => static fn(int $min): int => ($min | 1) + 100,
            'random' => static function (int $length, bool $letters) use (&$counter): string {
                $counter++;

                return str_pad(($letters ? 'l' : 'p') . $counter, $length, $letters ? 'x' : '0');
            },
        ],
    );
}

/**
 * The Robust of an existing grid, with these settings (by the names the planner knows them: gridName, baseHostname, publicPort,
 * privatePort, consolePort, consoleHost, consoleUser, consolePass, webUrl, dbHost, dbName, dbUser, dbPass).
 */
function robust_current(array $set): RobustController
{
    $directory = test_tmp() . '/robustcurrent-' . bin2hex(random_bytes(4));
    mkdir($directory, 0o755, true);
    $ini = ['[Const]'];
    foreach (
        [
            'baseHostname' => 'BaseHostname',
            'publicPort' => 'PublicPort',
            'privatePort' => 'PrivatePort',
            'webUrl' => 'WebURL',
        ]
        as $key => $name
    ) {
        isset($set[$key]) && ($ini[] = "$name = \"{$set[$key]}\"");
    }
    if (isset($set['gridName'])) {
        array_push($ini, '[GridInfoService]', "gridname = \"{$set['gridName']}\"");
    }
    $console = [
        'consolePort' => 'ConsolePort',
        'consoleHost' => 'ConsoleHost',
        'consoleUser' => 'ConsoleUser',
        'consolePass' => 'ConsolePass',
    ];
    if (array_intersect_key($console, $set) !== []) {
        $ini[] = '[Network]';
        foreach ($console as $key => $name) {
            isset($set[$key]) && ($ini[] = "$name = \"{$set[$key]}\"");
        }
    }
    if (array_intersect_key(['dbHost' => 1, 'dbName' => 1, 'dbUser' => 1, 'dbPass' => 1], $set) !== []) {
        $ini[] = '[DatabaseService]';
        $ini[] = sprintf(
            'ConnectionString = "Data Source=%s;Database=%s;User ID=%s;Password=%s;Old Guids=true;"',
            $set['dbHost'] ?? 'localhost',
            $set['dbName'] ?? 'robust',
            $set['dbUser'] ?? 'opensim',
            $set['dbPass'] ?? '',
        );
    }
    file_put_contents("$directory/Robust.ini", implode("\n", $ini) . "\n");

    return new RobustController(EnvironmentFake::with([]), "$directory/Robust.ini", 'amy');
}

describe('A new grid', function () {
    test(
        'is proposed from the machine: its name, its host, the block of ports, the database named after the nick',
        function () {
            $v = planner()->resolve();

            expect($v)
                ->toMatchArray([
                    'name' => 'Amy',
                    'nick' => 'amy',
                    'hypergrid' => true,
                    'spacing' => 0,
                    'core' => '/usr/share/opensim/0.9.3.0',
                    'hostname' => 'amy.example.org',
                    'public_port' => 8002,
                    'private_port' => 8003,
                    'console_port' => 8004,
                    'center' => '8002,8002',
                    'console' => 'rest',
                    'web_url' => 'https://amy.example.org',
                    'helpers' => false,
                    'helpers_path' => '/helpers',
                    'database_host' => 'localhost',
                    'database_name' => 'amy_robust',
                    'database_user' => 'opensim',
                ])
                ->and($v['database_password'])
                ->toBe('p1' . str_repeat('0', 18));
        },
    );

    test(
        'follows what was answered: the nick the name, the database the nick, the ports the public port, the address the host',
        function () {
            $v = planner()->resolve(['name' => 'My Grid', 'public_port' => 8012, 'hostname' => 'play.example.org']);

            expect($v)->toMatchArray([
                'nick' => 'my_grid',
                'database_name' => 'my_grid_robust',
                'private_port' => 8013,
                'console_port' => 8014,
                'center' => '8012,8012',
                'web_url' => 'https://play.example.org',
            ]);
        },
    );

    test('keeps what was answered over what follows, as text or as the numbers and booleans they are', function () {
        $v = planner()->resolve([
            'name' => 'My Grid',
            'nick' => 'mine',
            'public_port' => '8012',
            'private_port' => '9000',
            'console' => 'screen',
            'helpers' => 'yes',
            'hypergrid' => false,
            'center' => '10,20',
            'web_url' => 'https://web.example.org',
        ]);

        expect($v)->toMatchArray([
            'nick' => 'mine',
            'database_name' => 'mine_robust',
            'public_port' => 8012,
            'private_port' => 9000,
            'console_port' => 8014,
            'console' => 'screen',
            'helpers' => true,
            'hypergrid' => false,
            'center' => '10,20',
            'web_url' => 'https://web.example.org',
        ]);
    });

    test('takes the next free port from the machine when the public port does not end with 2', function () {
        $v = planner()->resolve(['public_port' => 8100]);

        expect($v['private_port'])
            ->toBe(8101 + 100)
            ->and($v['console_port'])
            ->toBe(((8101 + 100 + 1) | 1) + 100);
    });

    test(
        'is the plan the provisioner makes: folders by nick, the console made at random once, the helpers path normalized',
        function () {
            $planner = planner();
            $plan = $planner->plan(['name' => 'My Grid', 'helpers' => true, 'helpers_path' => 'helper/']);

            expect($plan)
                ->toBeInstanceOf(GridPlan::class)
                ->and($plan->gridName)
                ->toBe('My Grid')
                ->and($plan->gridNick)
                ->toBe('my_grid')
                ->and($plan->gridDir)
                ->toBe('/etc/opensim/grids/my_grid')
                ->and($plan->etcDirectory)
                ->toBe('/etc/opensim/grids/my_grid')
                ->and($plan->dataDirectory)
                ->toBe('/var/lib/opensim/data/my_grid')
                ->and($plan->cacheDirectory)
                ->toBe('/var/cache/opensim/my_grid')
                ->and($plan->logsDirectory)
                ->toBe('/var/log/opensim')
                ->and($plan->coreDirectory)
                ->toBe('/usr/share/opensim/0.9.3.0')
                ->and($plan->binDir)
                ->toBe('/usr/share/opensim/0.9.3.0/bin')
                ->and($plan->consoleMode)
                ->toBe('rest')
                ->and($plan->consolePort)
                ->toBe(8004)
                ->and($plan->consoleHost)
                ->toBe('amy.example.org')
                ->and(strlen($plan->consoleUser))
                ->toBe(12)
                ->and(strlen($plan->consolePass))
                ->toBe(32)
                ->and($plan->helpers)
                ->toBeTrue()
                ->and($plan->helpersPath)
                ->toBe('/helper')
                ->and($plan->dbName)
                ->toBe('my_grid_robust')
                // The password shown in the database screen is the one that is made
                ->and($plan->dbPass)
                ->toBe($planner->resolve()['database_password']);
        },
    );

    test(
        'has no console port with a screen session, and runs on the only core, or on the one chosen among several',
        function () {
            $plan = planner()->plan(['console' => 'screen']);
            expect($plan->consoleMode)
                ->toBe('screen')
                ->and($plan->consolePort)
                ->toBe(0)
                ->and($plan->consoleUser)
                ->toBe('');

            $several = planner(['cores' => ['/a/0.9.2.2' => '0.9.2.2', '/a/0.9.3.0' => '0.9.3.0']]);
            expect($several->plan()->coreDirectory)
                ->toBe('/usr/share/opensim/0.9.3.0')
                ->and($several->plan(['core' => '/a/0.9.2.2'])->coreDirectory)
                ->toBe('/a/0.9.2.2')
                ->and($several->fields()->get('core')['enable'])
                ->toBeTrue()
                ->and(planner()->fields()->get('core')['enable'])
                ->toBeFalse();
        },
    );

    test('says what is wrong in the answers, the ones given and the ones proposed', function () {
        $planner = planner();

        expect($planner->problems())
            ->toBe([])
            ->and(
                $planner->problems([
                    'public_port' => 'abc',
                    'spacing' => 80,
                    'center' => 'middle',
                    'name' => '',
                    'helpers' => true,
                    'helpers_path' => 'a b',
                ]),
            )
            ->toHaveKeys(['public_port', 'spacing', 'center', 'helpers_path']);
    });
});

describe('The options of a new grid', function () {
    test(
        'have their default: the search of the helpers, the beta currency, the OSSL of the core, the first web server the machine has',
        function () {
            $v = planner(['helpersAvailable' => true, 'webServer' => 'nginx'])->resolve();

            expect($v)
                ->toMatchArray([
                    'search' => 'internal',
                    'economy' => 'beta',
                    'ossl' => false,
                    'web_server' => 'nginx',
                    'web_site' => 'helpers',
                    'helpers' => true,
                ])
                // Without the helpers there is no search of the grid
                ->and(planner()->resolve()['search'])
                ->toBe('disabled');
        },
    );

    test('are the ones asked, among the choices there are', function () {
        $planner = planner(['helpersAvailable' => true]);
        $plan = $planner->plan([
            'search' => 'shared',
            'economy' => 'gloebit',
            'ossl' => 'yes',
            'web_server' => 'apache',
            'web_site' => 'website',
        ]);

        expect([$plan->search, $plan->economy, $plan->ossl, $plan->webServer, $plan->webSite])
            ->toBe(['shared', 'gloebit', true, 'apache', 'website'])
            ->and($planner->resolve(['search' => 'nothing', 'economy' => 'gold', 'web_server' => 'iis']))
            ->toMatchArray(['search' => 'internal', 'economy' => 'beta', 'web_server' => 'caddy']);
    });

    test(
        'keep the offline messages with the helpers when there are some, with Robust when there are none',
        function () {
            $with = planner(['helpersAvailable' => true]);
            $without = planner(['helpersAvailable' => false]);

            expect($with->plan([])->offline)
                ->toBe('helpers')
                ->and($without->plan([])->offline)
                ->toBe('robust')
                ->and($with->plan(['offline' => 'both'])->offline)
                ->toBe('both')
                ->and($with->plan(['offline' => 'robust'])->offline)
                ->toBe('robust')
                ->and($with->resolve(['offline' => 'nowhere'])['offline'])
                ->toBe('helpers')
                ->and(OpenSim\Provisioning\RobustConfig::connectors(true, true, 'helpers'))
                ->not->toContain('OfflineIMServiceConnector')
                ->and(OpenSim\Provisioning\RobustConfig::connectors(true, true, 'both'))
                ->toContain('OfflineIMServiceConnector')
                ->and(OpenSim\Provisioning\RobustConfig::connectors(false, false, 'robust'))
                ->toContain('OfflineIMServiceConnector');
        },
    );

    test('cannot say the offline messages are the helpers\' without the helpers', function () {
        $problems = planner(['helpersAvailable' => false])->problems(['helpers' => false, 'offline' => 'helpers']);

        expect($problems)
            ->toHaveKey('offline')
            ->and(planner(['helpersAvailable' => false])->problems(['helpers' => false, 'offline' => 'robust']))
            ->not->toHaveKey('offline');
    });

    test('cannot say the search is the grid\'s own without the helpers', function () {
        expect(planner(['helpersAvailable' => false])->problems(['search' => 'internal', 'helpers' => false]))
            ->toHaveKey('search')
            ->and(planner()->problems(['search' => 'shared', 'helpers' => false]))
            ->not->toHaveKey('search');
    });

    test('are not asked for a grid that exists', function () {
        $hidden = array_column(
            array_filter(
                Grid::fields(['modify' => true]),
                static fn(array $f): bool => ($f['enable'] ?? true) === false,
            ),
            'name',
        );

        expect($hidden)->toContain('nick', 'search', 'economy', 'ossl', 'web_server', 'web_site');
    });

    test('tell the simulators where the search is: the helpers, the shared provider or nobody', function () {
        $grid = Grid::described(EnvironmentFake::with(), [
            'planned' => true,
            'helpers' => true,
            'webUrl' => 'https://play.example.org',
            'helpersPath' => '/helpers',
        ]);

        expect($grid->helperUrl('query'))->toBe('https://play.example.org/helpers/query.php');
        $shared = $grid->with(['search' => 'shared']);
        expect($shared->helperUrl('query'))
            ->toBe('https://2do.directory/helpers/query.php')
            ->and($shared->helperUrl('register'))
            ->toBe('https://2do.directory/helpers/register.php')
            // What is not the search stays the grid's own
            ->and($shared->helperUrl('offline'))
            ->toBe('https://play.example.org/helpers/offline.php');
        $disabled = $grid->with(['search' => 'disabled']);
        expect($disabled->helperUrl('query'))->toBe('')->and($disabled->helperUrl('register'))->toBe('');
    });
});

describe('A grid that exists', function () {
    function existing_planner(array $more = []): GridPlanner
    {
        return planner(
            $more + [
                'modify' => true,
                'nick' => 'amy',
                'currentHypergrid' => false,
                'savedCenter' => '8100,8100',
                'savedSpacing' => 2,
                'current' => robust_current([
                    'gridName' => 'Amy World',
                    'baseHostname' => 'amy.old.org',
                    'publicPort' => 8102,
                    'privatePort' => 8105,
                    'consolePort' => 8108,
                    'webUrl' => 'https://web.old.org',
                    'dbHost' => 'db.old.org',
                    'dbName' => 'old_db',
                    'dbUser' => 'olduser',
                    'dbPass' => 'oldpass',
                    'consoleUser' => 'oldconsole',
                    'consolePass' => 'oldconsolepass',
                    'consoleHost' => 'console.old.org',
                ]),
            ],
        );
    }

    test('is proposed as it is, its nick stays', function () {
        $v = existing_planner()->resolve(['nick' => 'other']);

        expect($v)->toMatchArray([
            'name' => 'Amy World',
            'nick' => 'amy',
            'hypergrid' => false,
            'hostname' => 'amy.old.org',
            'public_port' => 8102,
            'private_port' => 8105,
            'console_port' => 8108,
            'center' => '8100,8100',
            'web_url' => 'https://web.old.org',
            'database_host' => 'db.old.org',
            'database_name' => 'old_db',
            'database_user' => 'olduser',
            'database_password' => 'oldpass',
            'spacing' => 2,
        ]);
    });

    test('keeps its console and its database password, and follows a public port that is changed', function () {
        $planner = existing_planner();
        $plan = $planner->plan();

        expect($plan->consoleUser)
            ->toBe('oldconsole')
            ->and($plan->consolePass)
            ->toBe('oldconsolepass')
            ->and($plan->consoleHost)
            ->toBe('console.old.org')
            ->and($plan->dbPass)
            ->toBe('oldpass');

        $moved = $planner->resolve(['public_port' => 8202]);
        expect($moved['private_port'])
            ->toBe(8203)
            ->and($moved['console_port'])
            ->toBe(8204)
            // The center it had is kept, it does not follow the port
            ->and($moved['center'])
            ->toBe('8100,8100');
    });

    test('is named by its nick in words when its files do not say its name', function () {
        expect(
            existing_planner(['nick' => 'watta_world', 'current' => robust_current(['dbPass' => 'x'])])->resolve()[
                'name'
            ],
        )->toBe('Watta World');
    });

    test('has a password made when the one found is the default of old', function () {
        expect(
            existing_planner(['current' => robust_current(['dbPass' => 'changeme'])])->resolve()['database_password'],
        )->toBe('p1' . str_repeat('0', 18));
    });

    test('is a console on a screen when its Robust has none', function () {
        expect(existing_planner(['current' => robust_current(['gridName' => 'X'])])->resolve()['console'])->toBe(
            'screen',
        );
    });
});

describe('fields of a grid', function () {
    test(
        'are the standard ones, the nick being hidden for a grid that exists, with the names of the setup file',
        function () {
            $names = array_column(Grid::fields(), 'name');

            expect($names)->toBe([
                'name',
                'nick',
                'hypergrid',
                'standalone',
                'spacing',
                'core',
                'hostname',
                'public_port',
                'private_port',
                'center',
                'console',
                'console_port',
                'web_url',
                'helpers',
                'helpers_path',
                'search',
                'offline',
                'economy',
                'ossl',
                'web_server',
                'web_site',
                'database_host',
                'database_name',
                'database_user',
                'database_password',
            ]);
            expect(Grid::fields(['modify' => true])[1]['enable'])
                ->toBeFalse()
                ->and(Grid::fields()[1]['enable'])
                ->toBeTrue();
        },
    );
});

describe('grid planner from an existing grid of this machine', function () {
    test('proposes its spacing, its center and its Robust settings as they are', function () {
        $machine = new Machine();
        $machine->write(
            $machine->grid . '/demo.conf',
            "[Grid]\nGridName = Demo\nRegionSpacing = 3\nCenter = \"1000,1000\"\nCoreDirectory = {$machine->core}\n",
        );

        $v = planner([
            'environment' => EnvironmentFake::with([
                'EtcRoot' => $machine->etc,
                'DataRoot' => $machine->root . '/data',
            ]),
        ])
            ->forNick('demo')
            ->resolve();

        expect($v)->toMatchArray([
            'spacing' => 3,
            'center' => '1000,1000',
            'public_port' => 8002,
            'private_port' => 8003,
            'hypergrid' => true,
        ]);
    });
});
