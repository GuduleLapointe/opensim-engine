<?php

declare(strict_types=1);

/**
 * The countdown before a shutdown.
 */

use OpenSim\Runtime\StopService;
use OpenSim\Testing\Machine;

describe('countdown', function () {
    test('counts down to the shutdown, not to the next warning', function () {
        $machine = new Machine();

        expect((new StopService($machine->environment))->countdown(40, 'Stopping x', 40))
            ->toBeTrue()
            ->and($machine->reporter->steps)->toBe(['  Stopping x in 80s'])
            ->and($machine->clock->total())->toBe(40.0);
    });

    test('shows each second on a terminal', function () {
        $machine = new Machine();
        $machine->reporter->terminal = true;

        (new StopService($machine->environment))->countdown(3, 'Stopping x');

        expect($machine->reporter->steps)->toBe(['  Stopping x in 3s', '  Stopping x in 2s', '  Stopping x in 1s']);
    });

    test('stops when the check succeeds, and says the wait was cut short', function () {
        $machine = new Machine();

        expect((new StopService($machine->environment))->countdown(40, 'x', 0, fn(): bool => true))
            ->toBeFalse()
            ->and($machine->clock->total())->toBe(5.0);
    });

    test('goes to the end when the check fails', function () {
        $machine = new Machine();

        expect((new StopService($machine->environment))->countdown(40, 'x', 0, fn(): bool => false))
            ->toBeTrue()
            ->and($machine->clock->total())->toBe(40.0);
    });
});
