<?php

declare(strict_types=1);

/**
 * A simulator is its config and the process that runs from it: what the config says is asked of the simulator.
 */

use OpenSim\Models\Grid;
use OpenSim\Models\Simulator;
use OpenSim\Testing\Machine;

describe('simulator', function () {
    test('tells what its own config says: ports, console, database, estate', function () {
        $machine = new Machine();
        $machine->write($machine->grid . '/sims/alpha.ini', <<<INI
            [Startup]
                regionload_regionsdir = "{$machine->grid}/sims/alpha/regions"
            [Network]
                http_listener_port = 9000
                ConsoleHost = "play.example.org"
                ConsoleUser = "admin"
                ConsolePass = "secret"
                console_port = 9004
            [DatabaseService]
                ConnectionString = "Data Source=db.example.org;Database=demo_alpha;User ID=simaccount;Password=simpass;Old Guids=true;"
            [Estates]
                DefaultEstateName = "Demo Estate"
                DefaultEstateOwnerName = "Ann Poe"
            INI);
        $alpha = Grid::find($machine->environment, 'demo')->simulator('alpha');

        expect($alpha->exists())->toBeTrue()
            ->and([$alpha->httpPort(), $alpha->consolePort(), $alpha->consoleHost(), $alpha->consoleUser(), $alpha->consolePassword()])
                ->toBe([9000, 9004, 'play.example.org', 'admin', 'secret'])
            ->and([$alpha->databaseHost(), $alpha->databaseName(), $alpha->databaseUser(), $alpha->databasePassword()])
                ->toBe(['db.example.org', 'demo_alpha', 'simaccount', 'simpass'])
            ->and([$alpha->estateName(), $alpha->estateOwner()])->toBe(['Demo Estate', 'Ann Poe'])
            ->and($alpha->detail())->toMatchArray([
                'name' => 'alpha',
                'grid' => 'demo',
                'enabled' => true,
                'running' => false,
                'httpPort' => 9000,
                'console' => 'rest',
                'databaseName' => 'demo_alpha',
                'estateOwner' => 'Ann Poe',
            ])
            ->and($alpha->detail())->not->toHaveKey('databasePassword')->not->toHaveKey('consolePassword');
    });

    test('says nothing it does not know, and nothing at all when it is not made yet', function () {
        $machine = new Machine();
        $ghost = new Simulator($machine->environment, $machine->grid . '/sims/ghost.ini');

        expect($ghost->exists())->toBeFalse()
            ->and([$ghost->httpPort(), $ghost->consolePort(), $ghost->databaseName(), $ghost->estateOwner()])->toBe([null, null, null, null])
            ->and($ghost->isEnabled())->toBeFalse();

        // Alpha of the machine has no database nor estate in its config
        $alpha = Grid::find($machine->environment, 'demo')->simulator('alpha');
        expect([$alpha->databaseName(), $alpha->estateName()])->toBe([null, null])->and($alpha->httpPort())->toBe(9000);
    });
});

describe('grid detail', function () {
    test('is all that is known of it, the password of its database left out', function () {
        $machine = new Machine();
        $detail = Grid::find($machine->environment, 'demo')->detail();

        expect($detail)->toMatchArray([
            'nick' => 'demo',
            'sources' => ['folder'],
            'enabled' => true,
            'hypergrid' => true,
            'publicPort' => 8002,
            'simulators' => ['alpha', 'beta'],
        ])->not->toHaveKey('databasePassword')
            ->and(json_encode($detail))->toBeString();
    });
});
