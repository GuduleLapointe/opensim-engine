<?php

declare(strict_types=1);

/**
 * The values packed in a number: flags by their value, labels by their bit.
 */

use OpenSim\Support\Bitmask;

describe('bitmask', function () {
    test('names the flags a number has, in the order of the list', function () {
        $names = [1 => 'DefaultRegion', 2 => 'FallbackRegion', 4 => 'Online', 16 => 'Persistent'];

        expect(Bitmask::names(0, $names))
            ->toBe([])
            ->and(Bitmask::names(5, $names))
            ->toBe(['DefaultRegion', 'Online'])
            ->and(Bitmask::names(1029, $names))
            ->toBe(['DefaultRegion', 'Online'])
            ->and(Bitmask::names(31, $names))
            ->toBe(['DefaultRegion', 'FallbackRegion', 'Online', 'Persistent']);
    });

    test('gives the labels of the bits a number has, the first label being the bit 0', function () {
        expect(Bitmask::labels(0, ['Build', 'Explore', 'Meet']))
            ->toBe([])
            ->and(Bitmask::labels(5, ['Build', 'Explore', 'Meet']))
            ->toBe(['Build', 'Meet'])
            ->and(Bitmask::labels(255, ['Build', 'Explore']))
            ->toBe(['Build', 'Explore']);
    });

    test('tells, sets and clears a flag', function () {
        expect(Bitmask::has(6, 4))
            ->toBeTrue()
            ->and(Bitmask::has(6, 1))
            ->toBeFalse()
            ->and(Bitmask::has(6, 0))
            ->toBeFalse()
            ->and(Bitmask::has(6, 6))
            ->toBeTrue()
            ->and(Bitmask::has(4, 6))
            ->toBeFalse()
            ->and(Bitmask::with(4, 1))
            ->toBe(5)
            ->and(Bitmask::without(7, 2))
            ->toBe(5);
    });
});
