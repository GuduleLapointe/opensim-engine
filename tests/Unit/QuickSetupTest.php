<?php

declare(strict_types=1);

/**
 * The quick setup: a few answers, the whole setup they stand for, and what is wrong with them by the name of the question.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Provisioning\QuickSetup;
use OpenSim\Testing\Machine;

/** The answers of a person who knows what they want. */
function quick_answers(array $set = []): array
{
    return $set + [
        'type' => 'grid',
        'name' => 'Magic Grid',
        'login_uri' => 'http://login.example.org:8102',
        'avatar' => 'Ada Love',
        'password' => 'secret123',
        'email' => 'ada@example.org',
    ];
}

describe('quick setup of a grid', function () {
    test('is the grid and its first simulator, with the address, the avatar that owns them and the proposals for the rest', function () {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers());
        $setup = $result['setup'];

        expect($result['valid'])
            ->toBeTrue()
            ->and($result['problems'])
            ->toBe([])
            ->and($setup['grid'])
            ->toMatchArray([
                'name' => 'Magic Grid',
                'nick' => 'magic_grid',
                'standalone' => false,
                'hostname' => 'login.example.org',
                'public_port' => 8102,
            ])
            ->and($setup['grid']['private_port'])
            ->not->toBe(8102)
            ->and($setup['simulators'])
            ->toHaveCount(1)
            ->and($setup['simulators'][0])
            ->toMatchArray(['name' => 'Welcome', 'estate' => ['name' => 'Magic Grid Estate', 'owner' => 'Ada Love']])
            ->and($setup['simulators'][0]['regions'][0]['name'])
            ->toBe('Welcome')
            ->and($setup['owner'])
            ->toMatchArray(['name' => 'Ada Love', 'email' => 'ada@example.org'])
            ->and(json_encode($result))
            ->not->toContain('secret123');
    });

    test('proposes everything when nothing is answered, and asks only for the avatar and its password', function () {
        $result = QuickSetup::plan((new Machine())->environment, []);

        expect($result['valid'])
            ->toBeFalse()
            ->and(array_keys($result['problems']))
            ->toBe(['avatar', 'password'])
            ->and($result['setup']['grid']['name'])
            ->not->toBe('')
            ->and($result['setup']['grid']['public_port'])
            ->toBeInt();
    });
});

describe('quick setup of a standalone', function () {
    test('is one simulator that is the whole grid: its name, its region, its port, its database', function () {
        $setup = QuickSetup::plan((new Machine())->environment, quick_answers(['type' => 'standalone', 'name' => 'My Island']))['setup'];

        expect($setup['grid'])
            ->toMatchArray(['name' => 'My Island', 'standalone' => true, 'public_port' => 8102, 'private_port' => 8102])
            ->and($setup['simulators'])
            ->toHaveCount(1)
            ->and($setup['simulators'][0])
            ->toMatchArray(['name' => 'My Island', 'http_port' => 8102])
            ->and($setup['simulators'][0]['regions'][0]['name'])
            ->toBe('My Island')
            ->and($setup['simulators'][0]['database']['name'])
            ->toBe($setup['grid']['database']['name']);
    });
});

describe('quick setup, what is wrong', function () {
    test('is told by the name of the question', function () {
        $result = QuickSetup::plan((new Machine())->environment, [
            'type' => 'island',
            'login_uri' => 'https://login.example.org',
            'avatar' => 'Ada',
            'password' => 'abc',
            'email' => 'not an address',
        ]);

        expect($result['valid'])
            ->toBeFalse()
            ->and(array_keys($result['problems']))
            ->toEqualCanonicalizing(['type', 'login_uri', 'avatar', 'password', 'email']);
    });

    test('has the hypergrid unless it is said to be off', function () {
        $environment = (new Machine())->environment;

        expect(QuickSetup::plan($environment, quick_answers())['setup']['grid']['hypergrid'])
            ->toBeTrue()
            ->and(QuickSetup::plan($environment, quick_answers(['hypergrid' => false]))['setup']['grid']['hypergrid'])
            ->toBeFalse()
            ->and(QuickSetup::plan($environment, quick_answers(['hypergrid' => 'no']))['setup']['grid']['hypergrid'])
            ->toBeFalse()
            ->and(QuickSetup::plan($environment, quick_answers(['hypergrid' => 'maybe']))['problems'])
            ->toHaveKey('hypergrid');
    });

    test('takes a login address as a host and a port, with or without http, and nothing else', function (string $uri, ?string $host, ?int $port) {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers(['login_uri' => $uri]));

        if ($host === null) {
            expect($result['problems'])->toHaveKey('login_uri');

            return;
        }
        expect($result['problems'])
            ->not->toHaveKey('login_uri')
            ->and($result['setup']['grid']['hostname'])
            ->toBe($host)
            ->and($result['setup']['grid']['public_port'])
            ->toBe($port ?? $result['setup']['grid']['public_port']);
    })->with([
        'a host' => ['login.example.org', 'login.example.org', null],
        'a host and a port' => ['login.example.org:8102', 'login.example.org', 8102],
        'with http' => ['http://login.example.org:8102/', 'login.example.org', 8102],
        'an address' => ['127.0.0.1:9000', '127.0.0.1', 9000],
        'a typo of the scheme' => ['htp:/login.example.org:8102', null, null],
        'another scheme' => ['ftp://login.example.org', null, null],
        'a space' => ['login example.org', null, null],
        'a port out of range' => ['login.example.org:99999', null, null],
        'https, not handled yet' => ['https://login.example.org', null, null],
    ]);

    test('lists every value it defines, by section, for whoever shows the setup', function () {
        $rows = QuickSetup::plan((new Machine())->environment, quick_answers())['rows'];
        $labels = array_column($rows, 'label');

        expect(array_values(array_unique(array_column($rows, 'section'))))
            ->toBe(['grid', 'simulator', 'region', 'owner'])
            ->and($labels)
            ->toContain('Grid name', 'Login URI', 'Hypergrid', 'Grid database', 'Simulator', 'Region', 'Port', 'Avatar')
            ->and($rows[1])
            ->toBe(['section' => 'grid', 'label' => 'Login URI', 'value' => 'http://login.example.org:8102'])
            ->and(json_encode($rows))
            ->not->toContain('secret123');
    });

    test('keeps a login address that has no scheme or no port', function () {
        $grid = QuickSetup::plan((new Machine())->environment, quick_answers(['login_uri' => 'login.example.org']))['setup']['grid'];

        expect($grid['hostname'])->toBe('login.example.org')->and($grid['public_port'])->toBeInt();
    });
});

describe('quick setup through the API', function () {
    test('is asked with a document and answered with a document', function () {
        $router = new Router(new Api((new Machine())->environment));

        $answer = $router->post('/setups/quick', [
            'data' => ['type' => 'setups', 'attributes' => quick_answers(['type' => 'standalone'])],
        ]);
        $data = json_decode($answer->body(), true)['data'];

        expect($answer->status)
            ->toBe(200)
            ->and([$data['type'], $data['id']])
            ->toBe(['setups', 'quick'])
            ->and($data['attributes']['valid'])
            ->toBeTrue()
            ->and($router->get('/setups/quick')->status)
            ->toBe(405)
            ->and($router->post('/setups/quick', '{"data":{"type":"forms"}}')->status)
            ->toBe(409);
    });
});

describe('quick setup, the words of the problems', function () {
    test('are one or two words, the label is the display\'s', function () {
        $problems = QuickSetup::plan((new Machine())->environment, [
            'type' => 'island',
            'login_uri' => 'x y',
            'avatar' => 'Ada',
            'password' => 'ab',
            'email' => 'nope',
        ])['problems'];

        expect($problems)->toBe([
            'type' => 'invalid',
            'login_uri' => 'invalid (host:port)',
            'avatar' => 'invalid (First Last)',
            'password' => 'too short (6 min)',
            'email' => 'invalid',
        ]);
        expect(QuickSetup::plan((new Machine())->environment, quick_answers(['password' => 'ab"cdef']))['problems'])->toBe([
            'password' => 'invalid (no double quote)',
        ])->and(array_keys(QuickSetup::plan((new Machine())->environment, [])['problems']))->toBe(['avatar', 'password']);
        expect(QuickSetup::plan((new Machine())->environment, [])['problems']['avatar'])->toBe('required');
    });
});

describe('quick setup, the names and the host', function () {
    test('lowers the case of the host, whatever way it is given', function () {
        $environment = (new Machine())->environment;

        expect(QuickSetup::plan($environment, quick_answers(['login_uri' => 'HTTP://Login.Example.ORG:8102']))['setup']['grid']['hostname'])
            ->toBe('login.example.org');
    });

    test('replaces the accents of an avatar name and asks the person to validate the change', function () {
        $environment = (new Machine())->environment;

        $first = QuickSetup::plan($environment, quick_answers(['avatar' => 'José García']));
        $again = QuickSetup::plan($environment, quick_answers(['avatar' => $first['corrected']['avatar']]));

        expect($first['valid'])
            ->toBeFalse()
            ->and($first['problems'])
            ->toBe(['avatar' => 'transliterated'])
            ->and($first['corrected'])
            ->toBe(['avatar' => 'Jose Garcia'])
            ->and($again['valid'])
            ->toBeTrue()
            ->and($again['corrected'])
            ->toBe([]);
    });

    test('does the same for the name of a standalone, which is its region, and checks the rest of it', function () {
        $environment = (new Machine())->environment;

        $first = QuickSetup::plan($environment, quick_answers(['type' => 'standalone', 'name' => 'Île Noël']));
        $bad = QuickSetup::plan($environment, quick_answers(['type' => 'standalone', 'name' => 'My/Island']));
        $grid = QuickSetup::plan($environment, quick_answers(['name' => 'Île Noël']));

        expect($first['corrected'])
            ->toBe(['name' => 'Ile Noel'])
            ->and($first['problems'])
            ->toBe(['name' => 'transliterated'])
            ->and($first['setup']['simulators'][0]['regions'][0]['name'])
            ->toBe('Ile Noel')
            ->and($bad['problems'])
            ->toBe(['name' => 'invalid (letters, digits, space)'])
            ->and($grid['corrected'])
            ->toBe(['name' => 'Ile Noel']);
    });
});

describe('quick setup, what separates and what is a space', function () {
    test('refuses the dot, the at sign and the underscore in the name of an avatar: the hypergrid\'s, and taken for a space', function (string $name) {
        expect(QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => $name]))['problems'])
            ->toBe(['avatar' => 'invalid (First Last)']);
    })->with(['a dot' => 'Ada.Love Smith', 'an at sign' => 'Ada@Love Smith', 'an underscore' => 'Ada_Love Smith', 'three words' => 'Ada Love Smith', 'one word' => 'Ada']);

    test('accepts the hyphen in the name of an avatar', function () {
        expect(QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => 'Ada-Marie Love-Smith']))['problems'])->toBe([]);
    });

    test('refuses the underscore in the name of a region: it is a space for Robust', function () {
        expect(QuickSetup::plan((new Machine())->environment, quick_answers(['type' => 'standalone', 'name' => 'My_Island']))['problems'])
            ->toBe(['name' => 'invalid (letters, digits, space)']);
    });

    test('transliterates the names of every alphabet', function () {
        $first = QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => 'Владимир Путин']));

        expect($first['corrected']['avatar'])
            ->toMatch('/^[A-Za-z0-9]+ [A-Za-z0-9]+$/')
            ->and($first['problems'])
            ->toBe(['avatar' => 'transliterated']);
    });

    test('refuses a domain name in unicode: the viewers refuse it in the address of a grid', function () {
        $quick = QuickSetup::plan((new Machine())->environment, quick_answers(['login_uri' => 'Bücher.Example:8102']));

        expect($quick['problems'])->toBe(['login_uri' => 'invalid (host:port)']);
    });

    test('gives a standalone the name of its grid for its simulator and its region, the dots being spaces', function () {
        $quick = QuickSetup::plan((new Machine())->environment, quick_answers(['type' => 'standalone', 'name' => 'Solo.World']));

        expect($quick['problems'])
            ->toBe([])
            ->and($quick['setup']['grid']['name'])
            ->toBe('Solo.World')
            ->and($quick['setup']['simulators'][0]['regions'][0]['name'])
            ->toBe('Solo World');
    });
});

describe('quick setup, the alphabets', function () {
    test('transliterates the grid name and the avatar name, and removes what a name cannot have after that', function () {
        $arabic = QuickSetup::plan((new Machine())->environment, quick_answers(['name' => 'مرحباً', 'avatar' => 'مرحباً Lapointe']));

        expect($arabic['corrected'])
            ->toBe(['name' => 'mrhbaan', 'avatar' => 'mrhbaan Lapointe'])
            ->and($arabic['problems'])
            ->toBe(['name' => 'transliterated', 'avatar' => 'transliterated'])
            ->and($arabic['setup']['grid']['name'])
            ->toBe('mrhbaan');
    });

    test('leaves an error when what comes out is not what a name must be: three words are not First Last', function () {
        $three = QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => '你好嗎']));
        $mixed = QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => '早安 Lapointe']));
        $two = QuickSetup::plan((new Machine())->environment, quick_answers(['avatar' => '你好']));

        expect($three['corrected'])
            ->toBe(['avatar' => 'ni hao ma'])
            ->and($three['problems'])
            ->toBe(['avatar' => 'invalid (First Last)'])
            ->and($mixed['corrected'])
            ->toBe(['avatar' => 'zao an Lapointe'])
            ->and($mixed['problems'])
            ->toBe(['avatar' => 'invalid (First Last)'])
            // Two words come out: right, but the person sees the change and validates it
            ->and($two['corrected'])
            ->toBe(['avatar' => 'ni hao'])
            ->and($two['problems'])
            ->toBe(['avatar' => 'transliterated']);
    });

    test('does not touch a Latin name, even with an apostrophe: the validation says what is wrong', function () {
        $grid = QuickSetup::plan((new Machine())->environment, quick_answers(['name' => "Olivier's Grid"]));

        expect($grid['corrected'])->toBe([])->and($grid['problems'])->toBe([]);
    });
});

