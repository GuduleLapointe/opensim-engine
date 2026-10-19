<?php

declare(strict_types=1);

/**
 * What the grids say of themselves, kept for a while: asked once in a while and not at every call, never served older than that, and
 * the grid they lead to is the one they say.
 */

use OpenSim\Models\Avatar;
use OpenSim\Config\GridConfig;
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
    test('are kept for a while, not asked at every call', function () {
        $answers = ['http://grid.example.org:8002/get_grid_info' => GRID_SAYS];
        $asked = [];
        $machine = grid_info_world($answers, $asked);
        $cache = new GridInfoCache($machine->environment);

        $first = $cache->read('http://grid.example.org:8002');
        $second = $cache->read('http://grid.example.org:8002/');
        $machine->clock->now += GridInfoCache::TTL - 1;
        $third = $cache->read('http://grid.example.org:8002');

        expect($first['mood'])
            ->toBe('free')
            ->and($second)
            ->toBe($first)
            ->and($third)
            ->toBe($first)
            ->and($asked)
            ->toHaveCount(1);
    });

    test(
        'are asked again when their time is over, and never served older: a grid that does not answer then is a failure',
        function () {
            $answers = ['http://grid.example.org:8002/get_grid_info' => GRID_SAYS];
            $asked = [];
            $machine = grid_info_world($answers, $asked);
            $cache = new GridInfoCache($machine->environment);
            $cache->read('http://grid.example.org:8002');

            $machine->clock->now += GridInfoCache::TTL + 1;
            $answers = [];

            expect($cache->read('http://grid.example.org:8002'))->toBeNull()->and($asked)->toHaveCount(2);
        },
    );

    test('keep a failure for a short time, so that a grid that is down is not asked again at once', function () {
        $answers = [];
        $asked = [];
        $machine = grid_info_world($answers, $asked);
        $cache = new GridInfoCache($machine->environment);

        expect($cache->read('http://down.example.org:8002'))
            ->toBeNull()
            ->and($cache->read('http://down.example.org:8002'))
            ->toBeNull()
            ->and($asked)
            ->toHaveCount(1);
        $answers['http://down.example.org:8002/get_grid_info'] = GRID_SAYS;
        $machine->clock->now += GridInfoCache::FAILED_TTL + 1;

        expect($cache->read('http://down.example.org:8002'))->not->toBeNull()->and($asked)->toHaveCount(2);
    });

    test('are asked whatever is kept when the information is saved', function () {
        $answers = ['http://grid.example.org:8002/get_grid_info' => GRID_SAYS];
        $asked = [];
        $cache = new GridInfoCache(grid_info_world($answers, $asked)->environment);
        $cache->read('http://grid.example.org:8002');
        $cache->read('http://grid.example.org:8002', fresh: true);

        expect($asked)->toHaveCount(2);
    });

    test(
        'lead to the login the grid says, none when it does not answer, the address asked when it says none',
        function () {
            $answers = [
                'http://old.example.org:8002/get_grid_info' => GRID_SAYS,
                'http://bare.example.org:8002/get_grid_info' => '<gridinfo><gridname>Bare</gridname></gridinfo>',
            ];
            $asked = [];
            $cache = new GridInfoCache(grid_info_world($answers, $asked)->environment);

            expect($cache->login('old.example.org:8002'))
                ->toBe('http://grid.example.org:8002')
                ->and($cache->login('bare.example.org:8002'))
                ->toBe('http://bare.example.org:8002')
                ->and($cache->login('down.example.org:8002'))
                ->toBeNull()
                ->and($cache->login(''))
                ->toBeNull();
        },
    );
});

describe('the address of a visitor', function () {
    test('is the one the grid of the visitor says, not the one OpenSimulator kept when they came', function () {
        $answers = ['http://old.example.org:8002/get_grid_info' => GRID_SAYS];
        $asked = [];
        $cache = new GridInfoCache(grid_info_world($answers, $asked)->environment);
        $config = new GridConfig();
        $id = 'ec0b88d8-4f3c-43c0-bf8c-c34129f2bad8;http://old.example.org:8002/;Gudule Lapointe';

        $kept = Avatar::visitor($config, $id);
        $said = Avatar::visitor($config, $id, $cache);

        expect($kept->uri())
            ->toBe('gudule.lapointe@old.example.org:8002')
            ->and($said->uri())
            ->toBe('gudule.lapointe@grid.example.org:8002')
            ->and($said->id())
            ->toBe('ec0b88d8-4f3c-43c0-bf8c-c34129f2bad8');
    });

    test('is what was kept when their grid does not answer: there is nothing better', function () {
        $answers = [];
        $asked = [];
        $cache = new GridInfoCache(grid_info_world($answers, $asked)->environment);

        $visitor = Avatar::visitor(
            new GridConfig(),
            'ec0b88d8-4f3c-43c0-bf8c-c34129f2bad8;http://down.example.org:8002/;Gudule Lapointe',
            $cache,
        );

        expect($visitor->uri())->toBe('gudule.lapointe@down.example.org:8002');
    });
});
