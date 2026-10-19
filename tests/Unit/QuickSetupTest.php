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
