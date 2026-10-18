<?php

declare(strict_types=1);

use OpenSim\Provisioning\Slug;

describe('slug of a name', function () {
    test('is snake_case, the words kept apart', function (string $name, string $slug) {
        expect(Slug::slug($name))
            ->toBe($slug)
            ->and(Slug::nick($name))
            ->toBe($slug)
            ->and(Slug::instance($name))
            ->toBe($slug);
    })->with([
        ['The Rapist', 'the_rapist'],
        ["Olivier's grid", 'oliviers_grid'],
        ['OliviersGrid', 'oliviers_grid'],
        ['Speculoos  Welcome!', 'speculoos_welcome'],
        ['Joyeux Noël', 'joyeux_noel'],
    ]);
});

describe('name of a nick', function () {
    test('is its words, capitalized and apart', function (string $nick, string $name) {
        expect(Slug::nameOf($nick))->toBe($name);
    })->with([
        ['watta_world', 'Watta World'],
        ['speculoos', 'Speculoos'],
        ['my-grid_2', 'My Grid 2'],
        ['', ''],
    ]);

    test('gives a slug that is the nick again', function () {
        expect(Slug::slug(Slug::nameOf('watta_world')))->toBe('watta_world');
    });
});
