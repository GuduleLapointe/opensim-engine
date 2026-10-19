<?php

declare(strict_types=1);

/**
 * What the grids said of themselves when they were verified, kept for a day: nothing is asked but at a verification, a grid that is
 * offline does not make what was kept less valid, and the grid they lead to is the one they say.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Models\Avatar;
use OpenSim\Network\GridInfoCache;
use OpenSim\Testing\Machine;

/** A machine whose grids answer what the test puts in `$answers` (address => body), counting the questions. */
function grid_info_world(array &$answers, array &$asked): Machine
{
    $machine = new Machine();
    $machine->environment = new OpenSim\Runtime\Environment(
        $machine->environment->layout,
        $machine->environment->processes,
        $machine->environment->launcher,
        $machine->environment->consoles,
        $machine->clock,
        $machine->environment->reporter,
        null,
        $machine->environment->probe,
        static function (string $url) use (&$answers, &$asked): ?string {
            $asked[] = $url;

            return $answers[$url] ?? null;
        },
    );

    return $machine;
}

const GRID_SAYS = '<gridinfo><gridname>Example</gridname><login>http://grid.example.org:8002/</login><mood>free</mood></gridinfo>';

describe('the answers of the grids', function () {
    test('are asked at a verification only, and read as they were kept after it', function () {
        $answers = ['http://grid.example.org:8002/get_grid_info' => GRID_SAYS];
        $asked = [];
        $cache = new GridInfoCache(grid_info_world($answers, $asked)->environment);

        expect($cache->saved('http://grid.example.org:8002'))->toBeNull()->and($asked)->toBe([]);
        $said = $cache->verify('http://grid.example.org:8002');

        expect($said['mood'])
            ->toBe('free')
            ->and($cache->saved('http://grid.example.org:8002/'))
            ->toBe($said)
            ->and($cache->saved('http://grid.example.org:8002'))
            ->toBe($said)
            ->and($asked)
            ->toHaveCount(1);
    });

    test('are kept under the login the grid says too, whatever address it was asked at', function () {
        $answers = ['http://old.example.org:8002/get_grid_info' => GRID_SAYS];
        $asked = [];
        $cache = new GridInfoCache(grid_info_world($answers, $asked)->environment);
        $cache->verify('http://old.example.org:8002');

        expect($cache->saved('http://grid.example.org:8002')['gridname'])
            ->toBe('Example')
            ->and($cache->saved('http://old.example.org:8002')['gridname'])
            ->toBe('Example');
    });

    test(
        'are valid for a day, a grid that is offline for hours does not make them less valid, and then they are gone',
        function () {
            $answers = ['http://grid.example.org:8002/get_grid_info' => GRID_SAYS];
            $asked = [];
            $machine = grid_info_world($answers, $asked);
            $cache = new GridInfoCache($machine->environment);
            $cache->verify('http://grid.example.org:8002');

            $answers = [];
            $machine->clock->now += 6 * 3600;

            expect($cache->verify('http://grid.example.org:8002'))
                ->toBeNull()
                ->and($cache->saved('http://grid.example.org:8002')['mood'])
                ->toBe('free');
            $machine->clock->now += 17 * 3600;
            expect($cache->saved('http://grid.example.org:8002')['mood'])->toBe('free');
            $machine->clock->now += 2 * 3600;
            expect($cache->saved('http://grid.example.org:8002'))->toBeNull();
        },
    );

    test('are replaced by what the grid says at the next verification', function () {
        $answers = ['http://grid.example.org:8002/get_grid_info' => GRID_SAYS];
        $asked = [];
        $cache = new GridInfoCache(grid_info_world($answers, $asked)->environment);
        $cache->verify('http://grid.example.org:8002');
        $answers['http://grid.example.org:8002/get_grid_info'] =
            '<gridinfo><gridname>Renamed</gridname><login>http://grid.example.org:8002/</login></gridinfo>';
        $cache->verify('http://grid.example.org:8002');

        expect($cache->saved('http://grid.example.org:8002'))->toBe([
            'gridname' => 'Renamed',
            'login' => 'http://grid.example.org:8002/',
        ]);
    });

    test(
        'lead to the login the grid said, the address asked when it said none, none when nothing was kept',
        function () {
            $answers = [
                'http://old.example.org:8002/get_grid_info' => GRID_SAYS,
                'http://bare.example.org:8002/get_grid_info' => '<gridinfo><gridname>Bare</gridname></gridinfo>',
            ];
            $asked = [];
            $cache = new GridInfoCache(grid_info_world($answers, $asked)->environment);

            expect($cache->login('old.example.org:8002'))
                ->toBeNull()
                ->and($asked)
                ->toBe([])
                ->and($cache->login('old.example.org:8002', verify: true))
                ->toBe('http://grid.example.org:8002')
                ->and($cache->login('old.example.org:8002'))
                ->toBe('http://grid.example.org:8002')
                ->and($cache->login('bare.example.org:8002', verify: true))
                ->toBe('http://bare.example.org:8002')
                ->and($cache->login('down.example.org:8002', verify: true))
                ->toBeNull()
                ->and($cache->login(''))
                ->toBeNull();
        },
    );
});

describe('the address of a visitor', function () {
    test('is the one the grid of the visitor said, not the one OpenSimulator kept when they came', function () {
        $answers = ['http://old.example.org:8002/get_grid_info' => GRID_SAYS];
        $asked = [];
        $cache = new GridInfoCache(grid_info_world($answers, $asked)->environment);
        $cache->verify('http://old.example.org:8002');
        $config = new GridConfig();
        $id = 'ec0b88d8-4f3c-43c0-bf8c-c34129f2bad8;http://old.example.org:8002/;Gudule Lapointe';

        $kept = Avatar::visitor($config, $id);
        $said = Avatar::visitor($config, $id, $cache);

        expect($kept->uri())
            ->toBe('gudule.lapointe@old.example.org:8002')
            ->and($said->uri())
            ->toBe('gudule.lapointe@grid.example.org:8002')
            ->and($said->id())
            ->toBe('ec0b88d8-4f3c-43c0-bf8c-c34129f2bad8')
            ->and($asked)
            ->toHaveCount(1);
    });

    test('is what was kept when nothing is known of their grid: there is nothing better', function () {
        $answers = [];
        $asked = [];
        $cache = new GridInfoCache(grid_info_world($answers, $asked)->environment);

        $visitor = Avatar::visitor(
            new GridConfig(),
            'ec0b88d8-4f3c-43c0-bf8c-c34129f2bad8;http://down.example.org:8002/;Gudule Lapointe',
            $cache,
        );

        expect($visitor->uri())->toBe('gudule.lapointe@down.example.org:8002')->and($asked)->toBe([]);
    });
});
