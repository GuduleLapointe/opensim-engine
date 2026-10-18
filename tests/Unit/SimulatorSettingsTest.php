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
        $machine->write(
            $machine->grid . '/sims/alpha.ini',
            <<<INI
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
            INI
            ,
        );
        $alpha = Grid::find($machine->environment, 'demo')->simulator('alpha');

        expect($alpha->exists())
            ->toBeTrue()
            ->and([
                $alpha->httpPort(),
                $alpha->consolePort(),
                $alpha->consoleHost(),
                $alpha->consoleUser(),
                $alpha->consolePassword(),
            ])
            ->toBe([9000, 9004, 'play.example.org', 'admin', 'secret'])
            ->and([$alpha->databaseHost(), $alpha->databaseName(), $alpha->databaseUser(), $alpha->databasePassword()])
            ->toBe(['db.example.org', 'demo_alpha', 'simaccount', 'simpass'])
            ->and([$alpha->estateName(), $alpha->estateOwner()])
            ->toBe(['Demo Estate', 'Ann Poe'])
            ->and($alpha->detail())
            ->toMatchArray([
                'name' => 'alpha',
                'grid' => 'demo',
                'enabled' => true,
                'running' => false,
                'httpPort' => 9000,
                'console' => 'rest',
                'databaseName' => 'demo_alpha',
                'estateOwner' => 'Ann Poe',
            ])
            ->and($alpha->detail())
            ->not->toHaveKey('databasePassword')
            ->not->toHaveKey('consolePassword');
    });

    test('says nothing it does not know, and nothing at all when it is not made yet', function () {
        $machine = new Machine();
        $ghost = new Simulator($machine->environment, $machine->grid . '/sims/ghost.ini');

        expect($ghost->exists())
            ->toBeFalse()
            ->and([$ghost->httpPort(), $ghost->consolePort(), $ghost->databaseName(), $ghost->estateOwner()])
            ->toBe([null, null, null, null])
            ->and($ghost->isEnabled())
            ->toBeFalse();

        // Alpha of the machine has no database nor estate in its config
        $alpha = Grid::find($machine->environment, 'demo')->simulator('alpha');
        expect([$alpha->databaseName(), $alpha->estateName()])
            ->toBe([null, null])
            ->and($alpha->httpPort())
            ->toBe(9000);
    });
});

describe('grid detail', function () {
    test('is all that is known of it, the password of its database left out', function () {
        $machine = new Machine();
        $detail = Grid::find($machine->environment, 'demo')->detail();

        expect($detail)
            ->toMatchArray([
                'nick' => 'demo',
                'sources' => ['folder'],
                'enabled' => true,
                'hypergrid' => true,
                'publicPort' => 8002,
                'simulators' => ['alpha', 'beta'],
            ])
            ->not->toHaveKey('databasePassword')
            ->and(json_encode($detail))
            ->toBeString();
    });
});

describe('Robust', function () {
    test('tells what its own config says: names, ports, console, database, services, roles of regions', function () {
        $machine = new Machine();
        $machine->write(
            $machine->grid . '/Robust.HG.ini',
            <<<INI
            [Const]
                BaseHostname = "play.example.org"
                PublicPort = 8002
                PrivatePort = 8003
                WebURL = "https://play.example.org"
            [GridInfoService]
                gridname = "Demo World"
            [Network]
                ConsolePort = 8404
                ConsoleHost = "play.example.org"
                ConsoleUser = "admin"
                ConsolePass = "secret"
            [DatabaseService]
                ConnectionString = "Data Source=db.example.org;Database=demo_robust;User ID=gridaccount;Password=gridpass;Old Guids=true;"
            [ServiceList]
                AssetServiceConnector = "8003/OpenSim.Server.Handlers.dll:AssetServiceConnector"
                BakedTextureService = "8003/OpenSim.Server.Handlers.dll:XBakesConnector"
            [GridService]
                Region_Welcome = "DefaultRegion, FallbackRegion"
            INI
            ,
        );
        $robust = Grid::find($machine->environment, 'demo')->robust();

        expect($robust->exists())
            ->toBeTrue()
            ->and([
                $robust->gridName(),
                $robust->baseHostname(),
                $robust->webUrl(),
                $robust->publicPort(),
                $robust->privatePort(),
            ])
            ->toBe(['Demo World', 'play.example.org', 'https://play.example.org', 8002, 8003])
            ->and([$robust->consolePort(), $robust->consoleHost(), $robust->consoleUser(), $robust->consolePassword()])
            ->toBe([8404, 'play.example.org', 'admin', 'secret'])
            ->and([
                $robust->databaseHost(),
                $robust->databaseName(),
                $robust->databaseUser(),
                $robust->databasePassword(),
            ])
            ->toBe(['db.example.org', 'demo_robust', 'gridaccount', 'gridpass'])
            ->and($robust->connectors())
            ->toBe(['AssetServiceConnector', 'BakedTextureService'])
            ->and($robust->serves('BakedTextureService'))
            ->toBeTrue()
            ->and($robust->serves('GroupsServiceConnector'))
            ->toBeFalse()
            ->and($robust->regionFlags())
            ->toBe(['DefaultRegion', 'FallbackRegion']);
    });
});

describe('simulator identity', function () {
    test('is its address, host and port, and its slug has the grid in it', function () {
        $machine = new Machine();
        $grid = Grid::find($machine->environment, 'demo');
        $alpha = $grid->simulator('alpha');
        $machine->write(
            $machine->grid . '/sims/alpha/regions/Plaza.ini',
            "[Plaza]\nRegionUUID = 11111111-1111-4111-8111-111111111111\nExternalHostName = \"play.example.org\"\n",
        );

        expect($alpha->uri())
            ->toBe('play.example.org:9000')
            ->and($alpha->id())
            ->toBe('play.example.org:9000')
            ->and($alpha->slug())
            ->toBe('alpha')
            ->and($alpha->detail())
            ->toMatchArray(['id' => 'play.example.org:9000', 'uri' => 'play.example.org:9000', 'slug' => 'alpha']);

        // SYSTEMIP is this machine; a simulator with no region yet has no host announced
        $machine->write($machine->grid . '/sims/alpha/regions/Plaza.ini', "[Plaza]\nExternalHostName = SYSTEMIP\n");
        expect($grid->simulator('alpha')->uri())->toBe(gethostname() . ':9000');
        $machine->write($machine->grid . '/sims/beta.ini', file_get_contents($machine->grid . '/sims/beta.ini'));
        expect($grid->simulator('beta')->uri())->toBe(gethostname() . ':9010');

        $ghost = new Simulator($machine->environment, $machine->grid . '/sims/ghost.ini');
        expect($ghost->uri())->toBeNull()->and($ghost->id())->toBe('ghost');
    });
});
