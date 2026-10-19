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
use OpenSim\Models\Simulator;
use OpenSim\Text\Uuid;

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
    test('has the name rule of Second Life: letters and digits, 3 to 50 characters, three words at most, corrected when it has accents', function () {
        expect(Region::validateName('Ma Region 2'))
            ->toBeNull()
            ->and(Region::validateName('Ma_Region'))
            ->toBe('invalid (letters, digits, space)')
            ->and(Region::validateName('Ma.Region'))
            ->toBe('invalid (letters, digits, space)')
            ->and(Region::validateName('Ma-Region'))
            ->toBe('invalid (letters, digits, space)')
            ->and(Region::validateName('Ab'))
            ->toBe('too short (3 min)')
            ->and(Region::validateName(str_repeat('a', 25)))
            ->toBeNull()
            ->and(Region::validateName(str_repeat('a', 51)))
            ->toBe('too long (50 max)')
            ->and(Region::validateName('Aaa Bbb Ccc Ddd'))
            ->toBe('too many words (3 max)')
            ->and(Region::validateName(''))
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
            ->toBe('Cafe Monde')
            ->and(Grid::validateName("Olivier's Grid"))
            ->toBeNull()
            ->and(Grid::validateName('Solo.World'))
            ->toBe("invalid (letters, digits, space ' -)");
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

describe('Names', function () {
    test('are asked the same way of every model that has one: validateName and normalizeName', function (string $model, string $name, ?string $problem, string $normalized) {
        expect($model::validateName($name))->toBe($problem)->and($model::normalizeName($name))->toBe($normalized);
    })->with([
        'a region' => [Region::class, 'Ma Region', null, 'Ma Region'],
        'a region with an accent' => [Region::class, 'Noël', null, 'Noel'],
        'a simulator' => [Simulator::class, 'Un-Deux', null, 'Un-Deux'],
        'a simulator with a dot' => [Simulator::class, 'Un.Deux', 'invalid (letters, digits, space -)', 'Un.Deux'],
        'a grid' => [Grid::class, "Olivier's Grid", null, "Olivier's Grid"],
    ]);
});

describe('Uuid', function () {
    test('is the one check of a UUID, the null key being nobody', function () {
        expect(Uuid::isValid('AAAAAAAA-0000-0000-0000-000000000001'))
            ->toBeTrue()
            ->and(Uuid::isValid('aaaaaaaa-0000-0000-0000-00000000000'))
            ->toBeFalse()
            ->and(Uuid::isValid('00000000-0000-0000-0000-000000000000'))
            ->toBeFalse()
            ->and(preg_match('/^' . Uuid::PATTERN . '$/', 'aaaaaaaa-0000-0000-0000-000000000001'))
            ->toBe(1);
    });
});
