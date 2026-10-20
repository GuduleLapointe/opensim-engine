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

    test('is snake_case for a table and a database, where neither a hyphen nor a dot can be', function (string $name, string $snake) {
        expect(Slug::snake($name))->toBe($snake);
    })->with([
        ['The Rapist', 'the_rapist'],
        ["Olivier's grid", 'oliviers_grid'],
        ['wonderful_green-field', 'wonderful_green_field'],
        ['demo.garden_sim', 'demo_garden_sim'],
        ['Joyeux Noël', 'joyeux_noel'],
    ]);
});

describe('nick of a grid', function () {
    test('is snake_case with the hyphens of the name kept, so that two names do not make one', function (string $name, string $nick) {
        expect(Slug::nick($name))->toBe($nick);
    })->with([
        ['The Rapist', 'the_rapist'],
        ["Olivier's grid", 'oliviers_grid'],
        ['My World', 'my_world'],
        ['Mary-Jane World', 'mary-jane_world'],
        ['Mary Jane-World', 'mary_jane-world'],
        ['Mary - Jane', 'mary_jane'],
        ['OliviersGrid', 'oliviers_grid'],
        ['wonderful_green-field', 'wonderful_green-field'],
        ['Joyeux Noël', 'joyeux_noel'],
        ['-Alone-', 'alone'],
    ]);
});

describe('instance of a simulator', function () {
    test('has its grid, a dot, its own name: the same words do not make the same simulator', function (
        string $grid,
        string $name,
        string $instance,
    ) {
        expect(Slug::simulator($grid, $name))->toBe($instance);
    })->with([
        ['Vonda', 'Welcome', 'vonda.welcome'],
        ['vonda', 'Welcome', 'vonda.welcome'],
        ['My World', 'Welcome', 'my_world.welcome'],
        ['My', 'World Welcome', 'my.world_welcome'],
        ['Mary-Jane World', 'Welcome Area', 'mary-jane_world.welcome_area'],
        ['wonderful_green', 'Field', 'wonderful_green.field'],
    ]);

    test('gives its own part back, the instances made before too', function () {
        expect(Slug::simulatorOf('wonderful.green_field', 'wonderful'))
            ->toBe('green_field')
            ->and(Slug::simulatorOf('mary-jane_world.welcome_area', 'Mary-Jane World'))
            ->toBe('welcome_area')
            // Made with an underscore after the grid, in kebab-case or in snake_case
            ->and(Slug::simulatorOf('wonderful_green-field', 'wonderful'))
            ->toBe('green-field')
            ->and(Slug::simulatorOf('wonderful-green_field', 'wonderful_green'))
            ->toBe('field')
            ->and(Slug::simulatorOf('wonderful_green_field', 'wonderful_green'))
            ->toBe('field')
            ->and(Slug::simulatorOf('vonda_welcome', 'another'))
            ->toBe('vonda_welcome');
    });

    test('may be one of the ones made before, the new one first', function () {
        expect(Slug::simulatorCandidates('Wonderful Green', 'Field'))->toBe([
            'wonderful_green.field',
            'wonderful-green_field',
            'wonderful_green_field',
        ]);
    });
});

describe('name of a nick', function () {
    test('is its words, capitalized and apart', function (string $nick, string $name) {
        expect(Slug::nameOf($nick))->toBe($name);
    })->with([
        ['watta_world', 'Watta World'],
        ['speculoos', 'Speculoos'],
        ['my-grid_2', 'My-Grid 2'],
        ['mary-jane_world', 'Mary-Jane World'],
        ['', ''],
    ]);

    test('gives a nick that is the nick again', function () {
        expect(Slug::nick(Slug::nameOf('watta_world')))
            ->toBe('watta_world')
            ->and(Slug::nick(Slug::nameOf('mary-jane_world')))
            ->toBe('mary-jane_world');
    });
});
