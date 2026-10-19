<?php

declare(strict_types=1);

/**
 * The state of a grid at a glance: its Robust, its simulators here and elsewhere, its regions, as data, as the lines of `opensim
 * status`, and as a picture for a prim; and the addresses and the info of a grid, as the API gives them.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenSim\Api\Api;
use OpenSim\Api\Handler;
use OpenSim\Data\Connections;
use OpenSim\Models\Grid;
use OpenSim\Runtime\GridMonitor;
use OpenSim\Testing\Machine;

/** The test machine with its Robust and its simulator alpha running, beta down. */
function monitor_machine(bool $robust = true): Machine
{
    $machine = new Machine();
    $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini');
    $robust &&
        $machine->processes->add(
            'dotnet Robust.dll -inifile=' . $machine->etc . '/robust.d/demo.ini',
            'opensim',
            0.3,
            1.2,
            50000,
        );

    return $machine;
}

/** The regions of the grid, and the tables its numbers are counted in. */
function monitor_database(): OpenSim\Data\OSPDO
{
    $db = region_database();
    $db->exec('ALTER TABLE UserAccounts ADD COLUMN active INT DEFAULT 1');
    $db->exec('CREATE TABLE GridUser (UserID CHAR(36), Login VARCHAR(16) DEFAULT 0)');
    $db->exec('CREATE TABLE Presence (UserID CHAR(36), RegionID CHAR(36))');
    $db->exec(
        "INSERT INTO Presence VALUES ('11111111-1111-1111-1111-111111111111', 'aaaaaaaa-0000-0000-0000-000000000001')",
    );

    return $db;
}

afterEach(fn() => Connections::using(null));

describe('The monitor of a grid', function () {
    test(
        'tells the instances of the machine, the simulators with their regions, and a level: degraded when one is down',
        function () {
            $machine = monitor_machine();
            $db = monitor_database();
            Connections::using(static fn() => $db);

            $monitor = GridMonitor::of(Grid::find($machine->environment, 'demo'), region_config());

            expect($monitor['level'])
                ->toBe('degraded')
                ->and(array_column($monitor['instances'], 'running', 'name'))
                ->toBe(['demo' => true, 'alpha' => true, 'beta' => false])
                ->and($monitor['regions'])
                ->toBe(['total' => 3, 'online' => 2])
                ->and(array_column($monitor['simulators'], 'online', 'name'))
                ->toBe(['alpha' => 2, 'beta' => 0])
                ->and(array_column($monitor['simulators'], 'regions', 'name'))
                ->toBe(['alpha' => 2, 'beta' => 1])
                ->and([$monitor['up'], $monitor['down']])
                ->toBe([2, 1])
                ->and($monitor['summary'])
                ->toStartWith('2 up, 1 down - regions 2/3');
        },
    );

    test(
        'is down when the Robust does not run, ok when everything runs, and shows a simulator that runs elsewhere',
        function () {
            $down = monitor_machine(robust: false);
            $db = monitor_database();
            Connections::using(static fn() => $db);
            $off = GridMonitor::of(Grid::find($down->environment, 'demo'), region_config());

            $up = monitor_machine();
            $up->processes->add('dotnet OpenSim.dll -inifile=' . $up->etc . '/opensim.d/beta.ini');
            $db = monitor_database();
            $db->exec("UPDATE regions SET flags = 4 WHERE uuid = 'aaaaaaaa-0000-0000-0000-000000000003'");
            $db->exec(
                "INSERT INTO regions (uuid, regionHandle, regionName, locX, locY, serverIP, serverPort, serverURI, flags) VALUES ('aaaaaaaa-0000-0000-0000-000000000009', 9, 'Remote', 1, 1, '10.9.9.9', 9100, 'http://remote.example.org:9100/', 4)",
            );
            Connections::using(static fn() => $db);
            $ok = GridMonitor::of(Grid::find($up->environment, 'demo'), region_config());

            expect($off['level'])
                ->toBe('down')
                ->and($ok['level'])
                ->toBe('ok')
                ->and($ok['regions'])
                ->toBe(['total' => 4, 'online' => 4])
                ->and(array_values(array_filter($ok['simulators'], static fn(array $s): bool => !$s['local'])))
                ->toBe([
                    [
                        'name' => 'remote.example.org:9100',
                        'uri' => 'http://remote.example.org:9100/',
                        'local' => false,
                        'running' => true,
                        'regions' => 1,
                        'online' => 1,
                    ],
                ]);
        },
    );

    test('is written as the table of opensim status, with a title and a summary', function () {
        $machine = monitor_machine();
        $db = monitor_database();
        Connections::using(static fn() => $db);

        $text = GridMonitor::text(GridMonitor::of(Grid::find($machine->environment, 'demo'), region_config()));

        expect($text)
            ->toStartWith("DEGRADED - Demo\nNAME")
            ->toMatch('/^demo\s+robust\s+up\s+-\s+0\.3\s+1\.2$/m')
            ->toMatch('/^alpha\s+simulator\s+up\s+2\/2\s/m')
            ->toMatch('/^beta\s+simulator\s+down\s+0\/1\s/m')
            ->toEndWith("\n");
    });
});

describe('The monitor, the addresses and the info of a grid, in the API', function () {
    function monitor_handler(Machine $machine, array &$drawn): Handler
    {
        $factory = new Psr17Factory();

        return new Handler(
            new Api($machine->environment, config: static fn() => region_config(), texts: text_renderer($drawn)),
            $factory,
            $factory,
            '/api',
        );
    }

    test('give the monitor as data, as text, and as a picture colored by its level', function () {
        $machine = monitor_machine();
        $db = monitor_database();
        Connections::using(static fn() => $db);
        $drawn = [];
        $handler = monitor_handler($machine, $drawn);

        $json = json_decode((string) api_ask($handler, 'GET', '/api/grids/demo/monitor')->getBody(), true);
        $text = api_ask($handler, 'GET', '/api/grids/demo/monitor?format=text');
        $png = api_ask($handler, 'GET', '/api/grids/demo/monitor?format=png');

        expect($json['data'])
            ->toMatchArray(['type' => 'monitor', 'id' => 'demo'])
            ->and($json['data']['attributes']['level'])
            ->toBe('degraded')
            ->and($text->getHeaderLine('Content-Type'))
            ->toBe('text/plain; charset=utf-8')
            ->and((string) $text->getBody())
            ->toStartWith('DEGRADED - Demo')
            ->and($png->getHeaderLine('Content-Type'))
            ->toBe('image/png')
            ->and($drawn[0][1])
            ->toBe('DejaVu-Sans-Mono')
            ->and($drawn[0][3])
            ->toBe('#ffcc44')
            ->and(api_ask($handler, 'GET', '/api/grids/nowhere/monitor')->getStatusCode())
            ->toBe(404);
    });

    test(
        'give the addresses a grid has, each with where it comes from, and every element it says of itself',
        function () {
            $machine = monitor_machine();
            $machine->write(
                $machine->grid . '/Robust.HG.ini',
                file_get_contents($machine->grid . '/Robust.HG.ini') .
                    "\n[Const]\n    WebURL = \"https://play.example.org\"\n[GridInfoService]\n    economy = \"https://play.example.org/helpers\"\n    gridname = \"Demo World\"\n    freeform = \"kept\"\n",
            );
            $drawn = [];
            $handler = monitor_handler($machine, $drawn);

            $addresses = json_decode((string) api_ask($handler, 'GET', '/api/grids/demo/addresses')->getBody(), true)[
                'data'
            ];
            $info = json_decode((string) api_ask($handler, 'GET', '/api/grids/demo/info')->getBody(), true)['data'];

            expect($addresses)
                ->toMatchArray(['type' => 'addresses', 'id' => 'demo'])
                ->and($addresses['attributes']['economy'])
                ->toBe('https://play.example.org/helpers')
                ->and($addresses['attributes']['addresses']['currency'])
                ->toBe('https://play.example.org/helpers/currency.php')
                ->and($addresses['attributes']['sources']['currency'])
                ->toBe('config')
                ->and($info)
                ->toMatchArray(['type' => 'info', 'id' => 'demo'])
                ->and($info['attributes']['live'])
                ->toBeFalse()
                ->and($info['attributes']['elements'])
                ->toMatchArray(['gridname' => 'Demo World', 'freeform' => 'kept'])
                ->and(api_ask($handler, 'GET', '/api/grids/nowhere/addresses')->getStatusCode())
                ->toBe(404)
                ->and(api_ask($handler, 'GET', '/api/grids/nowhere/info')->getStatusCode())
                ->toBe(404);
        },
    );
});
