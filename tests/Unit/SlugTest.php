<?php

declare(strict_types=1);

use OpenSim\Provisioning\Slug;

describe('The slug of a name', function () {
    test('is snake_case, the words kept apart', function (string $name, string $slug) {
        expect(Slug::slug($name))->toBe($slug)->and(Slug::nick($name))->toBe($slug)->and(Slug::instance($name))->toBe($slug);
    })->with([
        ['The Rapist', 'the_rapist'],
        ["Olivier's grid", 'oliviers_grid'],
        ['OliviersGrid', 'oliviers_grid'],
        ['Speculoos  Welcome!', 'speculoos_welcome'],
        ['Joyeux Noël', 'joyeux_noel'],
    ]);
});
