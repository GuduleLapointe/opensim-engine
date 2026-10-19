<?php

declare(strict_types=1);

/**
 * Joining a grid whose Robust runs elsewhere: what a simulator is given of it, from an address or from a preset.
 */

use OpenSim\Provisioning\RemoteGridPlanner;
use OpenSim\Testing\EnvironmentFake;

/** A planner whose install is a temporary tree, the grid at that address telling what it is given. */
function remote_planner(array $says = [], array $more = []): array
{
    $etc = test_tmp() . '/remote-' . bin2hex(random_bytes(4));
    mkdir("$etc/grids", 0o755, true);
    $calls = new ArrayObject();
    $planner = RemoteGridPlanner::with(
        $more + [
            'environment' => EnvironmentFake::with([
                'EtcRoot' => $etc,
                'CoreDirectory' => '/cores/0.9.3.0',
                'DataRoot' => '/data',
                'CacheRoot' => '/cache',
                'LogsRoot' => '/logs',
            ]),
            'says' => static function (string $host, int $port) use ($says, $calls): array {
                $calls[] = "$host:$port";

                return $says;
            },
        ],
    );

    return [$planner, $etc, $calls];
}

describe('A grid on another machine', function () {
    test('is described by its address, and tells its name and its nick itself', function () {
        [$planner, $etc, $calls] = remote_planner(['gridname' => 'Far World', 'gridnick' => 'far']);

        $result = $planner->plan(['address' => 'play.example.org:8102']);

        expect($result['error'])
            ->toBeNull()
            ->and($result['warnings'])
            ->toBe([])
            ->and($calls->getArrayCopy())
            ->toBe(['play.example.org:8102']);
        $grid = $result['grid'];
        expect($grid->isRemote())
            ->toBeTrue()
            ->and([$grid->nick(), $grid->name(), $grid->slug()])
            ->toBe(['far', 'Far World', 'far-world'])
            ->and([$grid->baseHostname(), $grid->publicPort(), $grid->privatePort()])
            ->toBe(['play.example.org', 8102, 8103])
            ->and($grid->isHypergrid())
            ->toBeTrue()
            ->and($grid->directory())
            ->toBe("$etc/grids/far")
            ->and($grid->coreDirectory())
            ->toBe('/cores/0.9.3.0')
            ->and([$grid->dataDirectory(), $grid->cacheDirectory(), $grid->logsDirectory()])
            ->toBe(['/data/far', '/cache/far', '/logs']);
    });

    test('takes the scheme and the port of the address, or the port of the scheme', function () {
        [$planner] = remote_planner(['gridname' => 'X']);

        expect($planner->plan(['address' => 'https://play.example.org:9000/'])['grid']->publicPort())
            ->toBe(9000)
            ->and($planner->plan(['address' => 'w4os.org:3405'])['grid']->publicPort())
            ->toBe(3405)
            ->and($planner->plan(['address' => 'play.example.org'])['grid']->publicPort())
            ->toBe(80)
            ->and($planner->plan(['address' => 'https://play.example.org'])['grid']->publicPort())
            ->toBe(443);
    });

    test('keeps what was answered over what the grid says, the private port included', function () {
        [$planner] = remote_planner(['gridname' => 'Far World', 'gridnick' => 'far']);

        $grid = $planner->plan([
            'address' => 'play.example.org:8002',
            'name' => 'My Name',
            'nick' => 'my-nick!',
            'private_port' => '9999',
            'hypergrid' => false,
        ])['grid'];

        expect([$grid->name(), $grid->nick(), $grid->privatePort(), $grid->isHypergrid()])->toBe([
            'My Name',
            'mynick',
            9999,
            false,
        ]);
    });

    test('is named after its host when it does not answer, and the person is told', function () {
        [$planner] = remote_planner([]);

        $result = $planner->plan(['address' => 'garden.example.org:8002']);

        expect($result['grid']->name())
            ->toBe('Garden')
            ->and($result['grid']->nick())
            ->toBe('garden')
            ->and($result['warnings'][0])
            ->toContain('did not answer at http://garden.example.org:8002/get_grid_info');
    });

    test('is not the Robust of this machine: a nick that a grid of this machine has is refused', function () {
        [$planner, $etc] = remote_planner(['gridname' => 'Far', 'gridnick' => 'mine']);
        mkdir("$etc/grids/mine", 0o755, true);
        file_put_contents("$etc/grids/mine/Robust.ini", "[Const]\n");

        $result = $planner->plan(['address' => 'play.example.org:8002']);

        expect($result['grid'])->toBeNull()->and($result['error'])->toContain("already has the nick 'mine'");
    });

    test('is kept as it was described when it is already known here', function () {
        [$planner, $etc] = remote_planner(['gridname' => 'Far', 'gridnick' => 'far']);
        mkdir("$etc/grids/far", 0o755, true);
        file_put_contents(
            "$etc/grids/far/far.conf",
            "[Grid]\nRemote = true\nGridName = Far Described\nBaseHostname = old.example.org\nPublicPort = 8010\nPrivatePort = 8011\n",
        );

        $result = $planner->plan(['address' => 'new.example.org:8002']);

        expect($result['notes'])
            ->toBe(["Grid 'far' is already known here, its description is kept."])
            ->and($result['grid']->name())
            ->toBe('Far Described')
            ->and($result['grid']->baseHostname())
            ->toBe('old.example.org');
    });

    test('is joined with what its preset says, and is not asked', function () {
        [$planner, , $calls] = remote_planner(
            [],
            [
                'presets' => ['speculoos' => 'Speculoos World'],
                'describe' => static fn(string $name): ?array => $name === 'speculoos'
                    ? [
                        'name' => 'Speculoos World',
                        'nick' => 'speculoos',
                        'host' => 'grid.speculoos.world',
                        'publicPort' => 8002,
                        'privatePort' => 8003,
                        'hypergrid' => true,
                    ]
                    : null,
            ],
        );

        $answers = $planner->presetAnswers('speculoos');
        $result = $planner->plan($answers, 'speculoos');

        expect($planner->presets())
            ->toBe(['speculoos' => 'Speculoos World'])
            ->and($planner->presetAnswers('nothing'))
            ->toBeNull()
            ->and($answers)
            ->toBe([
                'address' => 'grid.speculoos.world:8002',
                'name' => 'Speculoos World',
                'nick' => 'speculoos',
                'private_port' => 8003,
                'hypergrid' => true,
            ])
            ->and($calls->getArrayCopy())
            ->toBe([])
            ->and($result['warnings'])
            ->toBe([])
            ->and($result['grid']->preset())
            ->toBe('speculoos')
            ->and($result['grid']->nick())
            ->toBe('speculoos');
    });

    test('has an address, and a private port that is a number when it is given', function () {
        [$planner] = remote_planner();

        expect($planner->problems())
            ->toHaveKey('address')
            ->and($planner->problems(['address' => 'a:1', 'private_port' => 'x']))
            ->toHaveKey('private_port')
            ->and($planner->problems(['address' => 'a:1']))
            ->toBe([]);
    });
});
