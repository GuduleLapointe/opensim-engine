<?php

declare(strict_types=1);

/**
 * A grid is one object whatever it is read from: its folder, a plan, a description, what it says of itself.
 */

use OpenSim\Models\Grid;
use OpenSim\Network\GridInfoReader;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Runtime\Environment;
use OpenSim\Testing\Machine;
use OpenSim\Testing\EnvironmentFake;

describe('grid of this machine', function () {
    test('has what its files say: names, ports, database, folders, connectors', function () {
        $machine = new Machine();
        $machine->write(
            $machine->grid . '/Robust.HG.ini',
            <<<INI
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
            INI
            ,
        );
        $grid = Grid::find($machine->environment, 'demo');

        expect($grid->sources())
            ->toBe([Grid::FOLDER])
            ->and($grid->nick())
            ->toBe('demo')
            ->and($grid->name())
            ->toBe('Demo World')
            ->and($grid->isHypergrid())
            ->toBeTrue()
            ->and([$grid->baseHostname(), $grid->publicPort(), $grid->privatePort()])
            ->toBe(['play.example.org', 8002, 8003])
            ->and([$grid->databaseHost(), $grid->databaseName(), $grid->databaseUser(), $grid->databasePassword()])
            ->toBe(['db.example.org', 'demo_robust', 'gridaccount', 'gridpass'])
            ->and($grid->coreDirectory())
            ->toBe($machine->core)
            ->and($grid->dataDirectory())
            ->toBe($machine->root . '/data/demo')
            ->and($grid->connectors())
            ->toBe(['AssetServiceConnector', 'BakedTextureService'])
            ->and($grid->serves('BakedTextureService'))
            ->toBeTrue()
            ->and($grid->isRemote())
            ->toBeFalse()
            ->and($grid->isPlanned())
            ->toBeFalse()
            ->and($grid->robustIni())
            ->toBe($machine->grid . '/Robust.HG.ini')
            ->and($grid->robustInstance())
            ->toBe('demo')
            ->and($grid->simulatorNames())
            ->toBe(['alpha', 'beta']);
    });

    test(
        'is there when it has a Robust config or the description of a remote grid, and not when its folder has neither',
        function () {
            $machine = new Machine();
            mkdir($machine->etc . '/grids/empty');
            mkdir($machine->etc . '/grids/far');
            $machine->write(
                $machine->etc . '/grids/far/far.conf',
                "[Grid]\nRemote = true\nGridName = Far World\nBaseHostname = far.example.org\nPublicPort = 8102\nHypergrid = false\nPreset = osgrid\n",
            );

            $far = Grid::find($machine->environment, 'far');

            expect(Grid::find($machine->environment, 'empty'))
                ->toBeNull()
                ->and(Grid::find($machine->environment, 'nowhere'))
                ->toBeNull()
                ->and(array_map(static fn(Grid $g): string => $g->nick(), Grid::all($machine->environment)->items()))
                ->toContain('demo', 'far')
                ->not->toContain('empty')
                ->and($far->isRemote())
                ->toBeTrue()
                ->and($far->robustIni())
                ->toBeNull()
                ->and([
                    $far->name(),
                    $far->baseHostname(),
                    $far->publicPort(),
                    $far->privatePort(),
                    $far->isHypergrid(),
                    $far->preset(),
                ])
                ->toBe(['Far World', 'far.example.org', 8102, 8003, false, 'osgrid'])
                ->and($far->roles())
                ->toBe([])
                ->and($far->describe())
                ->toMatchArray(['nick' => 'far', 'baseHostname' => 'far.example.org', 'preset' => 'osgrid']);
        },
    );
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

        $grid = Grid::planned(EnvironmentFake::with([]), $plan);

        expect($grid->sources())
            ->toBe([Grid::PLAN])
            ->and($grid->isPlanned())
            ->toBeTrue()
            ->and([$grid->nick(), $grid->name(), $grid->slug(), $grid->directory()])
            ->toBe(['garden', 'Garden World', 'garden_world', '/nonexistent/grids/garden'])
            ->and($grid->robustIni())
            ->toBe('/nonexistent/grids/garden/Robust.ini')
            ->and([$grid->publicPort(), $grid->privatePort(), $grid->databaseName()])
            ->toBe([8102, 8103, 'garden_robust'])
            ->and($grid->isHypergrid())
            ->toBeFalse()
            ->and($grid->robust())
            ->toBeNull()
            ->and($grid->helperUrl('query'))
            ->toBe('https://garden.example.org/helpers/query.php');
    });
});

describe('grid described or announced', function () {
    test(
        'is a grid by the book for what its description does not say, and changes with the properties given',
        function () {
            $grid = Grid::described(EnvironmentFake::with(), ['nick' => 'far', 'baseHostname' => 'far.example.org']);

            expect($grid->sources())
                ->toBe([Grid::DESCRIBED])
                ->and($grid->in(Grid::FOLDER))
                ->toBeFalse()
                ->and([
                    $grid->publicPort(),
                    $grid->privatePort(),
                    $grid->databaseUser(),
                    $grid->helpersPath(),
                    $grid->search(),
                    $grid->isHypergrid(),
                ])
                ->toBe([8002, 8003, 'opensim', '/helpers', 'internal', true])
                ->and($grid->with(['publicPort' => 9000])->publicPort())
                ->toBe(9000)
                ->and($grid->publicPort())
                ->toBe(8002)
                ->and($grid->centerPlace())
                ->toBe([8002, 8002]);
        },
    );

    test('is what the grid says of itself at its public port', function () {
        $machine = new Machine();
        $machine->http['http://play.example.org:8002/get_grid_info'] =
            '<?xml version="1.0"?><gridinfo><gridname>Play &amp; Co</gridname><gridnick>play</gridnick><login>http://play.example.org:8002/</login></gridinfo>';

        $grid = Grid::announced($machine->environment, 'play.example.org', 8002);

        expect($grid->sources())
            ->toBe([Grid::ANNOUNCED])
            ->and([$grid->name(), $grid->nick(), $grid->baseHostname(), $grid->publicPort()])
            ->toBe(['Play & Co', 'play', 'play.example.org', 8002])
            ->and($grid->said())
            ->toMatchArray(['gridname' => 'Play & Co', 'login' => 'http://play.example.org:8002/'])
            ->and(Grid::announced($machine->environment, 'silent.example.org', 8002))
            ->toBeNull();
    });
});

describe('instance of a simulator', function () {
    test('is the one it has, else the one it will have, with the grid in it', function () {
        $machine = new Machine();
        $grid = Grid::find($machine->environment, 'demo');
        // Made as snake_case before
        $machine->write($machine->grid . '/sims/demo_green_field.ini', "[Network]\n    http_listener_port = 9020\n");

        expect($grid->instanceOf('Green Field'))
            ->toBe('demo_green_field')
            ->and($grid->simulator('demo_green_field')?->name())
            ->toBe('demo_green_field')
            // Asked as it would be made today, it is the same simulator
            ->and($grid->simulator('demo_green-field')?->name())
            ->toBe('demo_green_field')
            ->and($grid->instanceOf('Lake House'))
            ->toBe('demo_lake-house')
            ->and($grid->simulator('demo_lake-house'))
            ->toBeNull();

        $machine->write($machine->grid . '/sims/demo_lake-house.ini', "[Network]\n    http_listener_port = 9030\n");
        expect($grid->instanceOf('Lake House'))
            ->toBe('demo_lake-house')
            ->and($grid->simulator('demo_lake-house')?->name())
            ->toBe('demo_lake-house')
            ->and($grid->simulator('demo_lake-house')?->slug())
            ->toBe('demo_lake-house')
            ->and($grid->simulator('demo_lake-house')?->httpPort())
            ->toBe(9030);
    });
});

describe('what a grid says of itself', function () {
    /** The machine whose Robust says `[GridInfoService]` in its config, and a running grid that may say something else. */
    function grid_info_machine(?string $live = null): Machine
    {
        $machine = new Machine();
        $machine->write(
            $machine->grid . '/Robust.HG.ini',
            file_get_contents($machine->grid . '/Robust.HG.ini') .
                "\n[GridInfoService]\n    gridname = \"Demo\"\n    login = \"http://localhost:8002/\"\n    economy = \"https://play.example.org/helpers/\"\n    welcome = \"http://localhost/welcome\"\n",
        );
        $live !== null && ($machine->http['http://localhost:8002/get_grid_info'] = $live);

        return $machine;
    }

    test('is what the running Robust says, even when the config has changed since it started', function () {
        $machine = grid_info_machine(
            '<?xml version="1.0"?><gridinfo><gridname>Old name</gridname><login>http://localhost:8002/</login><economy>https://old.example.org/helpers/</economy></gridinfo>',
        );
        $grid = Grid::find($machine->environment, 'demo');

        expect($grid->gridInfoIsLive())
            ->toBeTrue()
            ->and($grid->gridInfo())
            ->toBe([
                'gridname' => 'Old name',
                'login' => 'http://localhost:8002/',
                'economy' => 'https://old.example.org/helpers/',
            ])
            ->and($grid->service('currency')->url())
            ->toBe('https://old.example.org/helpers/currency.php')
            ->and($grid->service('landtool')->url())
            ->toBe('https://old.example.org/helpers/landtool.php');
    });

    test('is what its config says when the grid does not answer, and says so', function () {
        $grid = Grid::find(grid_info_machine()->environment, 'demo');

        expect($grid->gridInfoIsLive())
            ->toBeFalse()
            ->and($grid->gridInfo())
            ->toBe([
                'gridname' => 'Demo',
                'login' => 'http://localhost:8002/',
                'economy' => 'https://play.example.org/helpers/',
                'welcome' => 'http://localhost/welcome',
            ])
            ->and($grid->service('currency')->url())
            ->toBe('https://play.example.org/helpers/currency.php');
    });

    test('is what an announced grid said, and nothing for a grid not made yet', function () {
        $machine = new Machine();
        $machine->http['http://play.example.org:8002/get_grid_info'] = '<gridinfo><gridname>Play</gridname></gridinfo>';

        expect(Grid::announced($machine->environment, 'play.example.org', 8002)->gridInfo())
            ->toBe(['gridname' => 'Play'])
            ->and(Grid::described($machine->environment, ['planned' => true, 'nick' => 'later'])->gridInfo())
            ->toBe([]);
    });
});

describe('the elements of get_grid_info', function () {
    test(
        'are all kept, the ones nobody planned, the empty ones, and the ones of an answer not well formed',
        function () {
            $free =
                '<?xml version="1.0"?><gridinfo><gridname>Free &amp; Open</gridname><mood>sunny</mood><empty></empty><closed/><x-custom_key>1</x-custom_key><nested><a>1</a></nested></gridinfo>';

            expect(GridInfoReader::parse($free))
                ->toBe([
                    'gridname' => 'Free & Open',
                    'mood' => 'sunny',
                    'empty' => '',
                    'closed' => '',
                    'x-custom_key' => '1',
                    'nested' => '',
                ])
                ->and(
                    GridInfoReader::parse(
                        '<gridinfo><gridname>Broken</gridname><mood>cloudy</mood><empty></empty><closed/>',
                    ),
                )
                ->toBe(['gridname' => 'Broken', 'mood' => 'cloudy', 'empty' => '', 'closed' => ''])
                ->and(GridInfoReader::read('not an address'))
                ->toBe([])
                ->and(
                    GridInfoReader::read(
                        'http://play.example.org:8002',
                        static fn(string $url): ?string => $url === 'http://play.example.org:8002/get_grid_info'
                            ? $free
                            : null,
                    )['mood'],
                )
                ->toBe('sunny');
        },
    );

    test('are all the keys of [GridInfoService] when the grid does not answer, empty values included', function () {
        $machine = new Machine();
        $machine->write(
            $machine->grid . '/Robust.HG.ini',
            file_get_contents($machine->grid . '/Robust.HG.ini') .
                "\n[GridInfoService]\n    gridname = \"Demo\"\n    mood = \"sunny\"\n    welcome = \"\"\n",
        );

        expect(Grid::find($machine->environment, 'demo')->gridInfo())->toBe([
            'gridname' => 'Demo',
            'mood' => 'sunny',
            'welcome' => '',
        ]);
    });
});

describe('the login address of a grid', function () {
    /** The answer of a grid asked at an address that is not the one it says for itself. */
    function renamed_grid_answer(): string
    {
        return '<?xml version="1.0"?><gridinfo><gridname>Example</gridname><gridnick>example</gridnick><login>http://grid.example.org:8002/</login></gridinfo>';
    }

    test('is the one the grid says, not the address it was reached at', function () {
        $machine = new Machine();
        $machine->http['http://old.example.org:8002/get_grid_info'] = renamed_grid_answer();

        $grid = Grid::announced($machine->environment, 'old.example.org', 8002);

        expect($grid->loginUrl())
            ->toBe('http://grid.example.org:8002')
            ->and($grid->uri())
            ->toBe('grid.example.org:8002')
            ->and([$grid->baseHostname(), $grid->publicPort()])
            ->toBe(['grid.example.org', 8002])
            ->and($grid->regionUri('Welcome', '128/128/22'))
            ->toBe('grid.example.org:8002/Welcome/128/128/22')
            ->and($grid->regionUri('Welcome'))
            ->toBe('grid.example.org:8002/Welcome');
    });

    test(
        'is the one a grid described here says, and none when it does not answer: no other address is tried',
        function () {
            $machine = new Machine();
            $machine->write(
                $machine->etc . '/grids/example/example.conf',
                "[Grid]\nGridName = Example\nRemote = true\nBaseHostname = grid.example.org\nPublicPort = 8002\n",
            );

            $silent = Grid::find($machine->environment, 'example');
            expect($silent->loginUrl())
                ->toBeNull()
                ->and($silent->uri())
                ->toBeNull()
                ->and($silent->baseHostname())
                ->toBe('grid.example.org');

            // The failure is kept for a short while: the grid is asked again when that time is over
            $machine->http['http://grid.example.org:8002/get_grid_info'] = renamed_grid_answer();
            $machine->clock->now += OpenSim\Network\GridInfoCache::FAILED_TTL + 1;
            $answering = Grid::find($machine->environment, 'example');
            expect($answering->loginUrl())
                ->toBe('http://grid.example.org:8002')
                ->and($answering->uri())
                ->toBe('grid.example.org:8002');
        },
    );

    test('is not given a port the grid does not give', function () {
        $machine = new Machine();
        $machine->http['http://secure.example.org:80/get_grid_info'] =
            '<gridinfo><gridname>Secure</gridname><login>https://login.example.org/</login></gridinfo>';

        $grid = Grid::announced($machine->environment, 'secure.example.org', 80);

        expect($grid->loginUrl())
            ->toBe('https://login.example.org')
            ->and($grid->uri())
            ->toBe('login.example.org')
            ->and($grid->publicPort())
            ->toBe(443);
    });
});
