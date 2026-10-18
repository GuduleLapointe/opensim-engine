<?php

declare(strict_types=1);

use OpenSim\Provisioning\Slug;

describe('slug of a name', function () {
    test('is kebab-case, the words kept apart', function (string $name, string $slug) {
        expect(Slug::slug($name))->toBe($slug);
    })->with([
        ['The Rapist', 'the-rapist'],
        ['Therapist', 'therapist'],
        ["Olivier's grid", 'oliviers-grid'],
        ['OliviersGrid', 'oliviers-grid'],
        ['Speculoos  Welcome!', 'speculoos-welcome'],
        ['Joyeux Noël', 'joyeux-noel'],
        ['wonderful_green', 'wonderful-green'],
    ]);

    test('is snake_case for a table, a database and the nick of a grid, where a hyphen cannot be', function (
        string $name,
        string $snake,
    ) {
        expect(Slug::snake($name))->toBe($snake)->and(Slug::nick($name))->toBe($snake);
    })->with([
        ['The Rapist', 'the_rapist'],
        ["Olivier's grid", 'oliviers_grid'],
        ['wonderful_green-field', 'wonderful_green_field'],
        ['Joyeux Noël', 'joyeux_noel'],
    ]);
});

describe('slug of a simulator', function () {
    test('has its grid, the underscore apart from the hyphens of the words', function (
        string $grid,
        string $name,
        string $slug,
    ) {
        expect(Slug::simulator($grid, $name))->toBe($slug);
    })->with([
        ['Vonda', 'Welcome', 'vonda_welcome'],
        ['vonda', 'Welcome', 'vonda_welcome'],
        // The same words do not make the same simulator
        ['Wonderful', 'Green Field', 'wonderful_green-field'],
        ['Wonderful Green', 'Field', 'wonderful-green_field'],
        ['wonderful_green', 'Field', 'wonderful-green_field'],
    ]);

    test('gives its own part back, the slugs made as snake_case before too', function () {
        expect(Slug::simulatorOf('wonderful_green-field', 'wonderful'))
            ->toBe('green-field')
            ->and(Slug::simulatorOf('wonderful-green_field', 'wonderful_green'))
            ->toBe('field')
            ->and(Slug::simulatorOf('wonderful_green_field', 'wonderful_green'))
            ->toBe('field')
            ->and(Slug::simulatorOf('vonda_welcome', 'another'))
            ->toBe('vonda_welcome');
    });
});

describe('name of a nick', function () {
    test('is its words, capitalized and apart', function (string $nick, string $name) {
        expect(Slug::nameOf($nick))->toBe($name);
    })->with([['watta_world', 'Watta World'], ['speculoos', 'Speculoos'], ['my-grid_2', 'My Grid 2'], ['', '']]);

    test('gives a nick that is the nick again', function () {
        expect(Slug::nick(Slug::nameOf('watta_world')))->toBe('watta_world');
    });
});
