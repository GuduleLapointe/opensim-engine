<?php

declare(strict_types=1);

/**
 * A standalone grid: one simulator serves the grid itself (its login, its services, its region), no Robust runs. Its folder says it
 * with `Standalone = true` in its description.
 */

use OpenSim\Controllers\OpenSimController;
use OpenSim\Models\Grid;
use OpenSim\Models\Service;
use OpenSim\Testing\Machine;

/** The test machine with a standalone `solo` beside the grid `demo`; the simulator `solo` is enabled when asked. */
function standalone_machine(bool $hypergrid = false, bool $enabled = true): Machine
{
    $machine = new Machine();
    $folder = $machine->etc . '/grids/solo';
    $machine->write(
        "$folder/solo.conf",
        "[Grid]\nGridName = Solo World\nStandalone = true\nHypergrid = " .
            ($hypergrid ? 'true' : 'false') .
            "\nBaseHostname = solo.example.org\nCoreDirectory = {$machine->core}\n",
    );
    $machine->write(
        "$folder/sims/solo.ini",
        "[Const]\n    BaseURL = \"http://solo.example.org\"\n[Architecture]\n    Include-Architecture = \"config-include/" .
            ($hypergrid ? 'StandaloneHypergrid' : 'Standalone') .
            ".ini\"\n[Network]\n    http_listener_port = 9000\n    console_port = 9004\n    ConsoleUser = u\n    ConsolePass = p\n",
    );
    $enabled && symlink("$folder/sims/solo.ini", $machine->etc . '/opensim.d/solo.ini');

    return $machine;
}

describe('standalone grid', function () {
    test('is known from its description, with its simulator as its only server', function () {
        $machine = standalone_machine();
        $grid = Grid::find($machine->environment, 'solo');

        expect($grid)
            ->not->toBeNull()
            ->and($grid->isStandalone())
            ->toBeTrue()
            ->and($grid->isRemote())
            ->toBeFalse()
            ->and($grid->name())
            ->toBe('Solo World')
            ->and($grid->isHypergrid())
            ->toBeFalse()
            ->and([$grid->baseHostname(), $grid->publicPort(), $grid->privatePort()])
            ->toBe(['solo.example.org', 9000, 9000])
            ->and($grid->robust())
            ->toBeNull()
            ->and($grid->robusts())
            ->toBe([])
            ->and($grid->detail()['standalone'])
            ->toBeTrue()
            ->and(Grid::find($machine->environment, 'demo')->isStandalone())
            ->toBeFalse()
            ->and(array_map(static fn(Grid $g): string => $g->nick(), Grid::all($machine->environment)->items()))
            ->toBe(['demo', 'solo']);
    });

    test('is run through its simulator: enabled with it, its instances are the simulator', function () {
        $machine = standalone_machine(enabled: false);
        $grid = Grid::find($machine->environment, 'solo');

        expect($grid->isEnabled())
            ->toBeFalse()
            ->and($grid->instances())
            ->toBe([])
            ->and($grid->enable())
            ->toBeTrue()
            ->and($grid->isEnabled())
            ->toBeTrue()
            ->and($grid->instances())
            ->toHaveCount(1)
            ->and($grid->instances()[0])
            ->toBeInstanceOf(OpenSimController::class);

        $grid->disable();
        expect($grid->isEnabled())->toBeFalse();
    });

    test('has its services served by its simulator, all at its address', function () {
        $machine = standalone_machine();
        $grid = Grid::find($machine->environment, 'solo');
        $asset = $grid->service('asset');

        expect($asset->isServed())
            ->toBeTrue()
            ->and($asset->provider())
            ->toBeInstanceOf(OpenSimController::class)
            ->and($asset->url())
            ->toBe('http://solo.example.org:9000')
            ->and($grid->service('login')->url())
            ->toBe('http://solo.example.org:9000')
            ->and($grid->service('gatekeeper'))
            ->toBeNull()
            ->and(count($grid->services()->items()))
            ->toBe(16);
    });

    test('has the services of the hypergrid when it is one', function () {
        $grid = Grid::find(standalone_machine(hypergrid: true)->environment, 'solo');

        expect($grid->service('gatekeeper'))
            ->toBeInstanceOf(Service::class)
            ->and($grid->service('gatekeeper')->port())
            ->toBe(Service::PUBLIC)
            ->and(count($grid->services()->items()))
            ->toBe(22);
    });
});
