<?php

declare(strict_types=1);

/**
 * From the answers about a simulator to its plan: what is proposed, what follows, a simulator that exists, the owner of its estate.
 */

use OpenSim\Models\Grid;
use OpenSim\Models\Simulator;
use OpenSim\Provisioning\SimulatorPlan;
use OpenSim\Provisioning\SimulatorPlanner;
use OpenSim\Testing\EnvironmentFake;

/** A grid of this machine: demo, Robust at 8002, its database account known. */
function sim_grid(array $set = []): Grid
{
    $directory = test_tmp() . '/simgrid-' . bin2hex(random_bytes(4));
    mkdir("$directory/sims", 0o755, true);

    return Grid::described(EnvironmentFake::with(), [
        'nick' => 'demo',
        'name' => 'Demo World',
        'directory' => $directory,
        'coreDirectory' => '/cores/0.9.3.0',
        'dataDirectory' => '/var/lib/demo',
        'cacheDirectory' => '/var/cache/demo',
        'logsDirectory' => '/var/log/demo',
        'databaseHost' => 'db.example.org',
        'databaseUser' => 'gridaccount',
        'databasePassword' => 'gridpass',
    ])->with($set);
}

/** A planner with the accounts of the grid and no random: a counter. */
function simulator_planner(array $more = []): SimulatorPlanner
{
    $counter = 0;

    return SimulatorPlanner::fromContext(
        $more + [
            'grid' => sim_grid(),
            'cores' => ['/cores/0.9.3.0' => '0.9.3.0'],
            'defaultName' => 'Welcome',
            'block' => 9000,
            'accounts' => ['Jane Doe', 'John Roe'],
            'nextPort' => static fn(int $min): int => $min + 100,
            'random' => static function (int $length, bool $letters) use (&$counter): string {
                $counter++;

                return str_pad(($letters ? 'l' : 'p') . $counter, $length, $letters ? 'x' : '0');
            },
        ],
    );
}

describe('A new simulator', function () {
    test(
        'is proposed in the first free block of ports, with the account of the grid, the estate of the grid, its first account',
        function () {
            $v = simulator_planner()->resolve();

            expect($v)->toMatchArray([
                'name' => 'Welcome',
                'slug' => 'demo_welcome',
                'core' => '/cores/0.9.3.0',
                'http_port' => 9000,
                'external_host' => 'SYSTEMIP',
                'console' => 'rest',
                'console_port' => 9004,
                'database_host' => 'db.example.org',
                'database_name' => 'demo_welcome',
                'database_user' => 'gridaccount',
                'database_password' => 'gridpass',
                'estate_name' => 'Demo World Estate',
                'estate_owner' => 'Jane Doe',
            ]);
        },
    );

    test('follows what was answered: the slug the name, the database its slug, the console its port', function () {
        $v = simulator_planner()->resolve(['name' => 'Garden Sim', 'http_port' => '9010']);

        expect($v)
            ->toMatchArray([
                'name' => 'Garden Sim',
                'slug' => 'demo_garden-sim',
                'database_name' => 'demo_garden_sim',
                'http_port' => 9010,
                'console_port' => 9014,
            ])
            ->and(simulator_planner()->resolve(['http_port' => 9010, 'console_port' => 9020])['console_port'])
            ->toBe(9020);
    });

    test('takes the next free port for a console when the port is not in a block of ten', function () {
        expect(simulator_planner()->resolve(['http_port' => 9005])['console_port'])->toBe(9006 + 100);
    });

    test('uses the database account of another simulator of the grid, its password too', function () {
        $grid = sim_grid();
        file_put_contents(
            "{$grid->directory()}/sims/demo_first.ini",
            "[DatabaseService]\nConnectionString = \"Data Source=sibling.example.org;Database=demo_first;User ID=sibling;Password=siblingpass;\"\n",
        );

        $v = simulator_planner(['grid' => $grid])
            ->forSimulator('Welcome')
            ->resolve();

        expect($v)->toMatchArray([
            'database_host' => 'sibling.example.org',
            'database_user' => 'sibling',
            'database_password' => 'siblingpass',
            'database_name' => 'demo_welcome',
        ]);
    });

    test('makes a password when the grid has none to give, the same one each time', function () {
        $planner = simulator_planner(['grid' => sim_grid(['databasePassword' => ''])]);

        expect($planner->resolve()['database_password'])
            ->toBe('p1' . str_repeat('0', 18))
            ->and($planner->plan()->dbPass)
            ->toBe('p1' . str_repeat('0', 18));
    });

    test(
        'is the plan the provisioner makes: folders by slug, the console made at random once, the grid it joins',
        function () {
            $plan = simulator_planner()->plan([
                'name' => 'Garden',
                'external_host' => 'play.example.org',
                'estate_owner' => 'John Roe',
            ]);

            expect($plan)
                ->toBeInstanceOf(SimulatorPlan::class)
                ->and($plan->gridNick)
                ->toBe('demo')
                ->and($plan->gridName)
                ->toBe('Demo World')
                ->and($plan->simulatorName)
                ->toBe('Garden')
                ->and($plan->slug)
                ->toBe('demo_garden')
                ->and($plan->dataDirectory)
                ->toBe('/var/lib/demo/demo_garden')
                ->and($plan->cacheDirectory)
                ->toBe('/var/cache/demo/demo_garden')
                ->and($plan->coreDirectory)
                ->toBe('/cores/0.9.3.0')
                ->and($plan->httpPort)
                ->toBe(9000)
                ->and($plan->externalHost)
                ->toBe('play.example.org')
                ->and($plan->consoleMode)
                ->toBe('rest')
                ->and($plan->consolePort)
                ->toBe(9004)
                ->and($plan->consoleHost)
                ->toBe('play.example.org')
                ->and(strlen($plan->consoleUser))
                ->toBe(12)
                ->and(strlen($plan->consolePass))
                ->toBe(32)
                ->and($plan->dbName)
                ->toBe('demo_garden')
                ->and($plan->estateName)
                ->toBe('Demo World Estate')
                ->and($plan->estateOwner)
                ->toBe('John Roe')
                ->and($plan->createOwner)
                ->toBeFalse();
        },
    );

    test(
        'has no console host when it is announced by the first interface, and no console with a screen session',
        function () {
            expect(simulator_planner()->plan()->consoleHost)->toBe('');

            $plan = simulator_planner()->plan(['console' => 'screen']);
            expect($plan->consoleMode)
                ->toBe('screen')
                ->and($plan->consolePort)
                ->toBe(9004)
                ->and($plan->consoleUser)
                ->toBe('');
        },
    );

    test('runs on the core of its grid, or on the one chosen among several', function () {
        $several = simulator_planner(['cores' => ['/cores/0.9.2.2' => '0.9.2.2', '/cores/0.9.3.0' => '0.9.3.0']]);

        expect($several->plan()->coreDirectory)
            ->toBe('/cores/0.9.3.0')
            ->and($several->plan(['core' => '/cores/0.9.2.2'])->coreDirectory)
            ->toBe('/cores/0.9.2.2')
            ->and($several->fields()->visible('core', []))
            ->toBeTrue()
            ->and(simulator_planner()->fields()->visible('core', []))
            ->toBeFalse();
    });
});

describe('owner of the estate', function () {
    test('is an account of the grid, or a new one when the person says so', function () {
        $planner = simulator_planner();
        $plan = $planner->plan([
            'estate_owner' => '+',
            'new_name' => 'Ann Poe',
            'new_password' => 'secret1',
            'new_email' => 'ann@example.org',
        ]);

        expect($plan->createOwner)
            ->toBeTrue()
            ->and([$plan->estateOwner, $plan->ownerPassword, $plan->ownerEmail])
            ->toBe(['Ann Poe', 'secret1', 'ann@example.org'])
            ->and($planner->fields()->visible('new_name', ['estate_owner' => '+']))
            ->toBeTrue()
            ->and($planner->fields()->visible('new_name', ['estate_owner' => 'Jane Doe']))
            ->toBeFalse()
            ->and($planner->fields()->get('estate_owner')['options'])
            ->toHaveKeys(['Jane Doe', 'John Roe', '+']);
    });

    test('is the first account to create when the grid has none', function () {
        $planner = simulator_planner(['accounts' => []]);
        $plan = $planner->plan(['new_name' => 'Ann Poe', 'new_password' => 'secret1']);

        expect($plan->createOwner)
            ->toBeTrue()
            ->and($plan->estateOwner)
            ->toBe('Ann Poe')
            ->and($planner->fields()->get('estate_owner'))
            ->toBeNull()
            ->and($planner->fields()->visible('new_name', []))
            ->toBeTrue();
    });

    test('is typed, and has to exist, when the accounts of the grid are not known here', function () {
        $planner = simulator_planner(['accounts' => null]);
        $plan = $planner->plan(['estate_owner' => 'Far Away']);

        expect($plan->createOwner)
            ->toBeFalse()
            ->and($plan->estateOwner)
            ->toBe('Far Away')
            ->and($planner->fields()->visible('new_name', []))
            ->toBeFalse()
            ->and($planner->problems(['estate_owner' => 'nobody']))
            ->toHaveKey('estate_owner');
    });
});

describe('owner a setup file names', function () {
    test(
        'is an account of the grid when it has it, a new account to make when it does not, one that has to exist when the accounts are not known',
        function () {
            $owner = ['name' => 'Jane Doe', 'password' => 'secret1', 'email' => 'jane@example.org'];

            expect(simulator_planner()->ownerAnswers($owner))
                ->toBe(['estate_owner' => 'Jane Doe'])
                ->and(simulator_planner()->ownerAnswers(['name' => 'Ann Poe'] + $owner))
                ->toBe([
                    'estate_owner' => '+',
                    'new_name' => 'Ann Poe',
                    'new_password' => 'secret1',
                    'new_email' => 'jane@example.org',
                ])
                ->and(simulator_planner(['accounts' => null])->ownerAnswers(['name' => 'Far Away']))
                ->toBe(['estate_owner' => 'Far Away'])
                ->and(simulator_planner()->ownerAnswers([]))
                ->toBe([]);

            $plan = simulator_planner(['accounts' => []])->plan(
                simulator_planner(['accounts' => []])->ownerAnswers(['name' => 'Ann Poe', 'password' => 'secret1']),
            );
            expect($plan->createOwner)->toBeTrue()->and($plan->estateOwner)->toBe('Ann Poe');
        },
    );
});

describe('A simulator that exists', function () {
    function existing_sim(): SimulatorPlanner
    {
        $grid = sim_grid();
        file_put_contents(
            "{$grid->directory()}/sims/demo_garden.ini",
            <<<INI
            [Network]
            http_listener_port = 9010
            ConsoleUser = "olduser"
            ConsolePass = "oldpass"
            console_port = 9014
            [DatabaseService]
            ConnectionString = "Data Source=old.example.org;Database=old_db;User ID=olduser;Password=oldsecret;"
            [Estates]
            DefaultEstateName = "Old Estate"
            DefaultEstateOwnerName = "John Roe"
            INI
            ,
        );

        return simulator_planner(['grid' => $grid, 'modify' => true, 'simulatorName' => 'Garden'])->forSimulator(
            'Garden',
        );
    }

    test('is proposed as it is, its name stays', function () {
        $v = existing_sim()->resolve(['name' => 'Other']);

        expect($v)->toMatchArray([
            'name' => 'Garden',
            'slug' => 'demo_garden',
            'http_port' => 9010,
            'console_port' => 9014,
            'database_host' => 'old.example.org',
            'database_name' => 'old_db',
            'database_user' => 'olduser',
            'database_password' => 'oldsecret',
            'estate_name' => 'Old Estate',
            'estate_owner' => 'John Roe',
        ]);
        expect(existing_sim()->fields()->visible('name', []))->toBeFalse();
    });

    test('keeps its console and its password, and follows a port that is changed', function () {
        $planner = existing_sim();
        $plan = $planner->plan();

        expect($plan->consoleUser)
            ->toBe('olduser')
            ->and($plan->consolePass)
            ->toBe('oldpass')
            ->and($plan->dbPass)
            ->toBe('oldsecret');
        expect($planner->resolve(['http_port' => 9020])['console_port'])->toBe(9024);
    });

    test('is a screen session when its config has no console', function () {
        $grid = sim_grid();
        file_put_contents("{$grid->directory()}/sims/demo_garden.ini", "[Network]\nhttp_listener_port = 9010\n");

        expect(
            simulator_planner(['grid' => $grid, 'modify' => true, 'simulatorName' => 'Garden'])
                ->forSimulator('Garden')
                ->resolve()['console'],
        )->toBe('screen');
    });
});

describe('answers about a simulator', function () {
    test(
        'are checked as they are given: its name, its ports, the owner and the password of an account to make',
        function () {
            $planner = simulator_planner();

            expect($planner->problems())
                ->toBe([])
                ->and($planner->problems(['name' => 'Not valid!', 'http_port' => 'abc']))
                ->toHaveKeys(['name', 'http_port'])
                ->and(
                    $planner->problems([
                        'estate_owner' => '+',
                        'new_name' => 'one',
                        'new_password' => 'short',
                        'new_email' => 'nobody',
                    ]),
                )
                ->toHaveKeys(['new_name', 'new_password', 'new_email'])
                ->and(
                    $planner->problems([
                        'estate_owner' => '+',
                        'new_name' => 'Ann Poe',
                        'new_password' => 'secret1',
                        'new_email' => '',
                    ]),
                )
                ->toBe([])
                ->and(Simulator::validateName('Noël'))
                ->toBeNull();
        },
    );
});

describe('A simulator of a standalone', function () {
    /** A planner for a standalone grid on 9000 whose database is solo, with no account yet. */
    function standalone_simulator_planner(): SimulatorPlanner
    {
        $grid = sim_grid([
            'standalone' => true,
            'publicPort' => 9000,
            'privatePort' => 9000,
            'databaseName' => 'solo',
        ]);

        return simulator_planner(['grid' => $grid, 'accounts' => []]);
    }

    test('serves the grid on its port, with the database of the grid, and makes the account of its owner', function () {
        $planner = standalone_simulator_planner();
        $answers = ['new_name' => 'Ada Love', 'new_password' => 'secret123', 'new_email' => 'ada@example.org'];
        $plan = $planner->plan($answers);

        expect($plan->standalone)
            ->toBeTrue()
            ->and($plan->httpPort)
            ->toBe(9000)
            ->and($plan->dbName)
            ->toBe('solo')
            ->and($plan->createOwner)
            ->toBeTrue()
            ->and($plan->ownerUuid)
            ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    });

    test('is the only one: another is refused, and the one that exists can be changed', function () {
        $solo = sim_grid(['standalone' => true, 'publicPort' => 9000]);
        $new = simulator_planner(['grid' => $solo, 'accounts' => []]);

        expect($new->problems(['name' => 'Welcome']))->not->toHaveKey('name');

        file_put_contents("{$solo->directory()}/sims/demo_welcome.ini", "[Network]\nhttp_listener_port = 9000\n");

        expect($new->problems(['name' => 'Second']))
            ->toHaveKey('name')
            ->and(
                simulator_planner(['grid' => $solo, 'modify' => true, 'simulatorName' => 'Welcome'])->problems([]),
            )
            ->not->toHaveKey('name')
            ->and(simulator_planner()->problems(['name' => 'Second']))
            ->not->toHaveKey('name');
    });
});
