<?php

declare(strict_types=1);

/**
 * The fields of the forms, in the standard format: which are shown for some answers, what is wrong with the answers.
 */

use OpenSim\Forms\FieldSet;

describe('A set of fields', function () {
    test('needs a name for each field, and finds them by name and by screen', function () {
        expect(fn() => new FieldSet([['label' => 'No name']]))->toThrow(InvalidArgumentException::class);

        $fields = new FieldSet([
            ['name' => 'a', 'group' => 'one'],
            ['name' => 'b', 'group' => 'two'],
            ['name' => 'c', 'group' => 'one'],
        ]);

        expect($fields->names())
            ->toBe(['a', 'b', 'c'])
            ->and($fields->group('one')->names())
            ->toBe(['a', 'c'])
            ->and($fields->get('b'))
            ->toBe(['name' => 'b', 'group' => 'two'])
            ->and($fields->get('z'))
            ->toBeNull();
    });

    test(
        'shows a field unless the model hides it or its condition is not met, a yes being true and a number being its text',
        function () {
            $fields = new FieldSet([
                ['name' => 'helpers'],
                ['name' => 'path', 'condition' => ['helpers' => true]],
                ['name' => 'console'],
                ['name' => 'port', 'condition' => ['console' => ['rest', 'remote'], 'helpers' => true]],
                ['name' => 'hidden', 'enable' => false],
                ['name' => 'level', 'condition' => ['n' => 2]],
            ]);

            expect($fields->visible('path', ['helpers' => 'yes']))
                ->toBeTrue()
                ->and($fields->visible('path', ['helpers' => false]))
                ->toBeFalse()
                ->and($fields->visible('path', []))
                ->toBeFalse()
                ->and($fields->visible('port', ['console' => 'remote', 'helpers' => true]))
                ->toBeTrue()
                ->and($fields->visible('port', ['console' => 'screen', 'helpers' => true]))
                ->toBeFalse()
                ->and($fields->visible('hidden', []))
                ->toBeFalse()
                ->and($fields->visible('helpers', []))
                ->toBeTrue()
                ->and($fields->visible('level', ['n' => '2']))
                ->toBeTrue()
                ->and($fields->visible('nothing', []))
                ->toBeFalse();
        },
    );

    test('says what is wrong, for the fields that are shown: required, then the callback of the field', function () {
        $fields = new FieldSet([
            ['name' => 'name', 'required' => true],
            ['name' => 'port', 'callback' => static fn($v) => ctype_digit((string) $v) ? null : 'A number'],
            [
                'name' => 'path',
                'validation' => static fn($v, $answers) => $v === $answers['expected'] ? null : 'Not the expected one',
            ],
            ['name' => 'optional'],
            ['name' => 'shown_when', 'required' => true, 'condition' => ['name' => 'x']],
        ]);

        expect($fields->problems(['name' => ' ', 'port' => 'abc', 'path' => 'a', 'expected' => 'b']))
            ->toBe([
                'name' => 'This field is required.',
                'port' => 'A number',
                'path' => 'Not the expected one',
            ])
            ->and($fields->problems(['name' => 'x', 'port' => '80', 'path' => 'b', 'expected' => 'b']))
            ->toBe(['shown_when' => 'This field is required.'])
            ->and($fields->problems(['name' => 'y', 'port' => '', 'path' => 'b', 'expected' => 'b']))
            ->toBe([]);
    });

    test('takes the callbacks of the models as callables of classes', function () {
        $fields = new FieldSet(OpenSim\Models\Grid::fields());

        expect($fields->problems(['name' => 'N', 'spacing' => 99, 'public_port' => 'x', 'center' => 'nowhere']))
            ->toHaveKeys(['spacing', 'public_port', 'center'])
            ->and(
                $fields->problems([
                    'name' => 'N',
                    'nick' => 'n',
                    'spacing' => 3,
                    'public_port' => 8002,
                    'private_port' => 8003,
                    'center' => '8002,8002',
                    'hostname' => 'h',
                    'web_url' => 'w',
                    'console_port' => 8004,
                    'database_host' => 'h',
                    'database_name' => 'd',
                    'database_user' => 'u',
                    'database_password' => 'p',
                    'helpers' => false,
                    'console' => 'rest',
                ]),
            )
            ->toBe([]);
    });
});
