<?php

declare(strict_types=1);

/**
 * The rules of the fields, which the models own: the names, the addresses, the email and the password. A form asks the fields of the
 * model, it has no rule of its own.
 */

use OpenSim\Forms\FieldSet;
use OpenSim\Models\Avatar;
use OpenSim\Models\Grid;
use OpenSim\Models\Region;

describe('Avatar', function () {
    test('has the name of an account that exists as OpenSimulator has it, and a new one without the dot, the at sign and the underscore', function () {
        expect(Avatar::validName('First.Last x'))
            ->toBeTrue()
            ->and(Avatar::validName('Ada'))
            ->toBeFalse()
            ->and(Avatar::validateNewName('Ada Love-Lace'))
            ->toBeNull()
            ->and(Avatar::validateNewName('Ada Lo_ve'))
            ->toBe('invalid (First Last)')
            ->and(Avatar::validateNewName('Ada.Love'))
            ->toBe('invalid (First Last)')
            ->and(Avatar::validateNewName('Ada Love Lace'))
            ->toBe('invalid (First Last)');
    });

    test('corrects a name to Latin letters without accents, in any alphabet', function (string $given, string $expected) {
        expect(Avatar::normalizeName($given))->toBe($expected);
    })->with([['José Lapointe', 'Jose Lapointe'], ['Ada Love', 'Ada Love'], ['Ada  Love', 'Ada  Love']]);

    test('has a password of 6 characters at least without a double quote, and an email without a space or a quote', function () {
        expect(Avatar::validatePassword('secret'))
            ->toBeNull()
            ->and(Avatar::validatePassword('short'))
            ->toBe('too short (6 min)')
            ->and(Avatar::validatePassword('with"quote'))
            ->toBe('invalid (no double quote)')
            ->and(Avatar::validateEmail(''))
            ->toBeNull()
            ->and(Avatar::validateEmail('ada@example.org'))
            ->toBeNull()
            ->and(Avatar::validateEmail('ada example.org'))
            ->toBe('invalid')
            ->and(Avatar::validEmail("a'b@example.org"))
            ->toBeFalse();
    });

    test('keeps the hash and the salt of a password as Robust does: 32 hexadecimal characters and a salt of letters and digits', function () {
        expect(Avatar::validPasswordHash(str_repeat('a', 32), 'Salt1'))
            ->toBeTrue()
            ->and(Avatar::validPasswordHash(str_repeat('a', 31), 'Salt1'))
            ->toBeFalse()
            ->and(Avatar::validPasswordHash(str_repeat('a', 32), 'no salt'))
            ->toBeFalse();
    });
});

describe('Region', function () {
    test('has a name of letters, digits, space, dot and hyphen, which is corrected when it has accents', function () {
        expect(Region::nameProblem('Ma Region-2.0'))
            ->toBeNull()
            ->and(Region::nameProblem('Ma_Region'))
            ->toBe('invalid (letters, digits, space . -)')
            ->and(Region::nameProblem(''))
            ->not->toBeNull()
            ->and(Region::normalizeName('Noël'))
            ->toBe('Noel');
    });
});

describe('Grid', function () {
    test('says what a login address says, with or without the scheme, and the host in lower case', function (string $uri, array $found, ?string $problem) {
        expect(Grid::parseLoginUri($uri))->toBe([$found, $problem]);
    })->with([
        ['', [], null],
        ['Amy.Local', ['hostname' => 'amy.local'], null],
        ['amy.local:8002', ['hostname' => 'amy.local', 'public_port' => 8002], null],
        ['http://Amy.local:9000/', ['hostname' => 'amy.local', 'public_port' => 9000], null],
        ['https://amy.local', ['hostname' => 'amy.local'], 'https not supported'],
        ['htp:/amy.local:8002', [], 'invalid (host:port)'],
        ['ftp://amy.local', [], 'invalid (host:port)'],
        ['amy.local:99999', [], 'invalid (host:port)'],
    ]);

    test('has a host name and a web address of its own, and corrects its name to Latin letters', function () {
        expect(Grid::validateHostname('play.example.org'))
            ->toBeNull()
            ->and(Grid::validateHostname('bücher.example'))
            ->toBeNull()
            ->and(Grid::validateHostname('not a host'))
            ->toBe('invalid (host name)')
            ->and(Grid::validateWebUrl('https://www.example.org/helpers'))
            ->toBeNull()
            ->and(Grid::validateWebUrl('www.example.org'))
            ->toBe('invalid (https://host)')
            ->and(Grid::normalizeName('Café Monde'))
            ->toBe('Cafe Monde');
    });

    test('forces its rules through its fields, whoever shows them', function () {
        $fields = new FieldSet(Grid::fields());

        expect($fields->problems(['hostname' => 'not a host', 'web_url' => 'www', 'name' => 'Café']))
            ->toMatchArray([
                'hostname' => 'invalid (host name)',
                'web_url' => 'invalid (https://host)',
                'name' => 'transliterated',
            ])
            ->and($fields->corrections(['name' => 'Café']))
            ->toBe(['name' => 'Cafe']);
    });
});
