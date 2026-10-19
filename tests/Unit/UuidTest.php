<?php

declare(strict_types=1);

/**
 * The UUIDs: the two keys that stand for nobody, and what counts as nothing.
 */

use OpenSim\Text\Uuid;

describe('UUID', function () {
    test('has two keys for nobody, the global constant being the first', function () {
        expect(NULL_KEY)
            ->toBe(Uuid::NULL_KEYS[0])
            ->and(Uuid::isNullKey('00000000-0000-0000-0000-000000000000'))
            ->toBeTrue()
            ->and(Uuid::isNullKey('00000000-0000-0000-0000-000000000001'))
            ->toBeTrue()
            ->and(Uuid::isNullKey(' 00000000-0000-0000-0000-000000000001 '))
            ->toBeTrue()
            ->and(Uuid::isNullKey('00000000-0000-0000-0000-000000000002'))
            ->toBeFalse()
            ->and(Uuid::isNullKey(null))
            ->toBeFalse()
            ->and(Uuid::isNullKey(0))
            ->toBeFalse();
    });

    test('is nothing when it is empty or a key for nobody', function () {
        foreach (
            [
                null,
                '',
                0,
                '0',
                false,
                [],
                '00000000-0000-0000-0000-000000000000',
                '00000000-0000-0000-0000-000000000001',
            ]
            as $nothing
        ) {
            expect(Uuid::isEmpty($nothing))->toBeTrue();
        }
        expect(Uuid::isEmpty('11111111-1111-1111-1111-111111111111'))->toBeFalse();
    });

    test('is valid when it has the shape of one, and the keys for nobody only when they are accepted', function () {
        expect(Uuid::isValid('11111111-1111-1111-1111-111111111111'))
            ->toBeTrue()
            ->and(Uuid::isValid('00000000-0000-0000-0000-000000000001'))
            ->toBeFalse()
            ->and(Uuid::isValid('00000000-0000-0000-0000-000000000001', nullok: true))
            ->toBeTrue()
            ->and(Uuid::isValid('', nullok: true))
            ->toBeTrue()
            ->and(Uuid::isValid('not a uuid'))
            ->toBeFalse();
    });
});
