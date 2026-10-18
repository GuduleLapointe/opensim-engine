<?php

declare(strict_types=1);

/**
 * A grid is one object whatever it is read from: its folder, a plan, a description, what it says of itself.
 */

use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Runtime\Environment;
use OpenSim\Testing\Machine;

describe('grid of this machine', function () {
    test('has what its files say: names, ports, database, folders, connectors', function () {
        $machine = new Machine();
        $machine->write($machine->grid . '/Robust.HG.ini', <<<INI
            [Const]
                BaseHostname = "play.example.org"
                PublicPort = 8002
                PrivatePort = 8003
            [GridInfoService]
                gridname = "Demo World"
            [DatabaseService]
                ConnectionString = "Data Source=db.example.org;Database=demo_robust;User ID=gridaccount;Password=gridpass;Old Guids=true;"
            [ServiceList]
                AssetServiceConnector = "8003/OpenSim.Server.Handlers.dll:AssetServiceConnector"
                BakedTextureService = "8003/OpenSim.Server.Handlers.dll:XBakesConnector"
            INI);
        $grid = Grid::find($machine->environment, 'demo');

        expect($grid->sources())->toBe([Grid::FOLDER])
            ->and($grid->nick())->toBe('demo')
            ->and($grid->name())->toBe('Demo World')
            ->and($grid->isHypergrid())->toBeTrue()
            ->and([$grid->baseHostname(), $grid->publicPort(), $grid->privatePort()])->toBe(['play.example.org', 8002, 8003])
            ->and([$grid->databaseHost(), $grid->databaseName(), $grid->databaseUser(), $grid->databasePassword()])->toBe(['db.example.org', 'demo_robust', 'gridaccount', 'gridpass'])
            ->and($grid->coreDirectory())->toBe($machine->core)
            ->and($grid->dataDirectory())->toBe($machine->root . '/data/demo')
            ->and($grid->connectors())->toBe(['AssetServiceConnector', 'BakedTextureService'])
            ->and($grid->serves('BakedTextureService'))->toBeTrue()
            ->and($grid->isRemote())->toBeFalse()
            ->and($grid->isPlanned())->toBeFalse()
            ->and($grid->robustIni())->toBe($machine->grid . '/Robust.HG.ini')
            ->and($grid->robustInstance())->toBe('demo')
            ->and($grid->simulatorNames())->toBe(['alpha', 'beta']);
    });

    test('is there when it has a Robust config or the description of a remote grid, and not when its folder has neither', function () {
        $machine = new Machine();
        mkdir($machine->etc . '/grids/empty');
        mkdir($machine->etc . '/grids/far');
        $machine->write($machine->etc . '/grids/far/far.conf', "[Grid]\nRemote = true\nGridName = Far World\nBaseHostname = far.example.org\nPublicPort = 8102\nHypergrid = false\nPreset = osgrid\n");

        $far = Grid::find($machine->environment, 'far');

        expect(Grid::find($machine->environment, 'empty'))->toBeNull()
            ->and(Grid::find($machine->environment, 'nowhere'))->toBeNull()
            ->and(array_map(static fn(Grid $g): string => $g->nick(), Grid::all($machine->environment)))->toContain('demo', 'far')->not->toContain('empty')
            ->and($far->isRemote())->toBeTrue()
            ->and($far->robustIni())->toBeNull()
            ->and([$far->name(), $far->baseHostname(), $far->publicPort(), $far->privatePort(), $far->isHypergrid(), $far->preset()])
                ->toBe(['Far World', 'far.example.org', 8102, 8003, false, 'osgrid'])
            ->and($far->roles())->toBe([])
            ->and($far->describe())->toMatchArray(['nick' => 'far', 'baseHostname' => 'far.example.org', 'preset' => 'osgrid']);
    });
});

describe('grid that is planned', function () {
    test('has what the plan says, with the Robust config the setup will write', function () {
        $plan = new GridPlan();
        $plan->gridName = 'Garden World';
        $plan->gridNick = 'garden';
        $plan->gridSlug = 'garden_world';
        $plan->gridDir = '/nonexistent/grids/garden';
        $plan->enableHypergrid = false;
        $plan->publicPort = 8102;
        $plan->privatePort = 8103;
        $plan->dbName = 'garden_robust';
        $plan->helpers = true;
        $plan->webUrl = 'https://garden.example.org';

        $grid = Grid::planned(Environment::forProfile([]), $plan);

        expect($grid->sources())->toBe([Grid::PLAN])
            ->and($grid->isPlanned())->toBeTrue()
            ->and([$grid->nick(), $grid->name(), $grid->slug(), $grid->directory()])->toBe(['garden', 'Garden World', 'garden_world', '/nonexistent/grids/garden'])
            ->and($grid->robustIni())->toBe('/nonexistent/grids/garden/Robust.ini')
            ->and([$grid->publicPort(), $grid->privatePort(), $grid->databaseName()])->toBe([8102, 8103, 'garden_robust'])
            ->and($grid->isHypergrid())->toBeFalse()
            ->and($grid->robust())->toBeNull()
            ->and($grid->helperUrl('query'))->toBe('https://garden.example.org/helpers/query.php');
    });
});

describe('grid described or announced', function () {
    test('is a grid by the book for what its description does not say, and changes with the properties given', function () {
        $grid = Grid::described(['nick' => 'far', 'baseHostname' => 'far.example.org']);

        expect($grid->sources())->toBe([Grid::DESCRIBED])
            ->and($grid->in(Grid::FOLDER))->toBeFalse()
            ->and([$grid->publicPort(), $grid->privatePort(), $grid->databaseUser(), $grid->helpersPath(), $grid->search(), $grid->isHypergrid()])->toBe([8002, 8003, 'opensim', '/helpers', 'internal', true])
            ->and($grid->with(['publicPort' => 9000])->publicPort())->toBe(9000)
            ->and($grid->publicPort())->toBe(8002)
            ->and($grid->centerPlace())->toBe([8002, 8002]);
    });

    test('is what the grid says of itself at its public port', function () {
        $machine = new Machine();
        $machine->http['http://play.example.org:8002/get_grid_info'] = '<?xml version="1.0"?><gridinfo><gridname>Play &amp; Co</gridname><gridnick>play</gridnick><login>http://play.example.org:8002/</login></gridinfo>';

        $grid = Grid::announced($machine->environment, 'play.example.org', 8002);

        expect($grid->sources())->toBe([Grid::ANNOUNCED])
            ->and([$grid->name(), $grid->nick(), $grid->baseHostname(), $grid->publicPort()])->toBe(['Play & Co', 'play', 'play.example.org', 8002])
            ->and($grid->said())->toMatchArray(['gridname' => 'Play & Co', 'login' => 'http://play.example.org:8002/'])
            ->and(Grid::announced($machine->environment, 'silent.example.org', 8002))->toBeNull();
    });
});
