<?php

declare(strict_types=1);

/**
 * The quick setup: a few answers, the whole setup they stand for, and what is wrong with them by the name of the question.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Router;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\QuickSetup;
use OpenSim\Provisioning\SimulatorPlan;
use OpenSim\Provisioning\DatabaseService;
use OpenSim\Provisioning\InstanceControl;
use OpenSim\Provisioning\UiInterface;
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
            ->toBe(['grid', 'options', 'simulator', 'region', 'owner'])
            ->and($labels)
            ->toContain('Grid name', 'Login URI', 'Hypergrid', 'Grid database', 'Simulator', 'Region', 'Port', 'Avatar')
            ->and($rows[1])
            ->toBe(['section' => 'grid', 'label' => 'Login URI', 'value' => 'http://login.example.org:8102'])
            ->and(json_encode($rows))
            ->not->toContain('secret123');
    });

    test('shows the options chosen and the ones that are on whatever the answers, with what the setup does not apply yet', function () {
        $quick = QuickSetup::plan((new Machine())->environment, quick_answers(['economy-gloebit' => true, 'search' => 'yes']));
        $options = array_column(array_filter($quick['rows'], static fn(array $r): bool => $r['section'] === 'options'), 'value', 'label');

        expect($options['Hypergrid'])
            ->toBe('yes')
            ->and($options['Economy: Gloebit'])
            ->toBe('yes')
            ->and($options['Cross-grid search'])
            ->toBe('yes, the shared provider (2do.directory)')
            ->and($options['SSL'])
            ->toBe('no (not implemented)')
            ->and($options['Extended OSSL'])
            ->toBe('yes')
            ->and(array_keys($options))
            ->toContain('Helpers', 'Search module (OpenSimSearch)', 'Offline messages: the grid (module V2)', 'Profiles', 'Destination guide', 'Grid status');
    });

    test('has the helpers, the cross-grid search and the extended OSSL on by default', function () {
        $quick = QuickSetup::plan((new Machine())->environment, quick_answers());

        expect($quick['setup']['grid']['helpers'])
            ->toBeTrue()
            ->and($quick['setup']['grid']['search'])
            ->toBe('shared')
            ->and($quick['setup']['grid']['economy'])
            ->toBe('beta')
            ->and($quick['setup']['grid']['options'])
            ->toContain('ossl-extended', 'search', 'profiles', 'offline-robust')
            ->and(array_column($quick['options'], 'value', 'name'))
            ->toBe(['ssl' => false, 'hypergrid' => true, 'helpers' => true, 'search' => true, 'economy-gloebit' => false, 'ossl-extended' => true]);
    });

    test('has no search of its own when the helpers are off and the shared one is not wanted, and refuses Gloebit without the helpers', function () {
        $environment = (new Machine())->environment;
        $off = QuickSetup::plan($environment, quick_answers(['helpers' => false, 'search' => false]));
        $gloebit = QuickSetup::plan($environment, quick_answers(['helpers' => false, 'economy-gloebit' => true]));

        expect($off['problems'])
            ->toBe([])
            ->and($off['setup']['grid']['search'])
            ->toBe('disabled')
            ->and($off['setup']['grid']['helpers'])
            ->toBeFalse()
            ->and($gloebit['problems'])
            ->toBe(['economy-gloebit' => 'needs Helpers']);
    });

    test('tells the options and the types, for whoever shows the form', function () {
        $quick = QuickSetup::plan((new Machine())->environment, quick_answers());

        expect(array_column($quick['options'], 'label', 'name'))
            ->toBe([
                'ssl' => 'SSL',
                'hypergrid' => 'Hypergrid',
                'helpers' => 'Helpers',
                'search' => 'Cross-grid Search',
                'economy-gloebit' => 'Economy: Gloebit',
                'ossl-extended' => 'Extended OSSL',
            ])
            ->and(array_column($quick['options'], 'implemented', 'name'))
            ->toBe(['ssl' => false, 'hypergrid' => true, 'helpers' => true, 'search' => true, 'economy-gloebit' => true, 'ossl-extended' => true])
            ->and($quick['setup']['grid']['options'])
            ->toContain('ossl-extended')
            ->and($quick['types'])
            ->toBe([
                ['name' => 'grid', 'label' => 'Grid', 'implemented' => true],
                ['name' => 'standalone', 'label' => 'Standalone', 'implemented' => true],
                ['name' => 'simulator', 'label' => 'Simulator (join other grid)', 'implemented' => true],
                ['name' => 'speculoos', 'label' => 'Speculoos', 'implemented' => true],
                ['name' => 'osgrid', 'label' => 'OSGrid', 'implemented' => true],
            ]);
    });

    test('says a grid that exists has a name that is taken, and nothing of its regions', function () {
        $environment = (new Machine())->environment;

        expect(QuickSetup::plan($environment, quick_answers(['name' => 'Demo']))['problems'])
            ->toBe(['name' => 'taken'])
            ->and(QuickSetup::plan($environment, quick_answers(['name' => 'Demo', 'type' => 'standalone']))['problems'])
            ->toBe(['name' => 'taken']);
    });

    test('proposes the password the grids of the machine already use for the same database account', function () {
        $machine = new Machine();
        $robust = $machine->grid . '/Robust.HG.ini';
        file_put_contents(
            $robust,
            (string) file_get_contents($robust) .
                "\n[DatabaseService]\n    ConnectionString = \"Data Source=localhost;Database=demo_robust;User ID=opensim;Password=sharedpass1234;Old Guids=true;\"\n",
        );

        $answers = QuickSetup::plan($machine->environment, quick_answers());
        $planner = \OpenSim\Provisioning\GridPlanner::forMachine($machine->environment);

        expect($answers['valid'])
            ->toBeTrue()
            ->and($planner->plan(['name' => 'Other Grid'])->dbPass)
            ->toBe('sharedpass1234')
            ->and($planner->plan(['name' => 'Other Grid', 'database_password' => 'mine'])->dbPass)
            ->toBe('mine');
    });

    test('refuses a type it does not know, and a boolean that is not one', function () {
        $environment = (new Machine())->environment;

        expect(QuickSetup::plan($environment, quick_answers(['type' => 'nothing']))['problems'])
            ->toBe(['type' => 'invalid'])
            ->and(QuickSetup::plan($environment, quick_answers(['ossl-extended' => 'maybe']))['problems'])
            ->toBe(['ossl-extended' => 'invalid']);
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

/** One step of the scenario: the reply to the screen before it (none for the first), filled and with the button pressed. */
function quick_step(Machine $machine, ?array $before, array $answers = [], string $button = 'next'): array
{
    return QuickSetup::next($machine->environment, ['state' => $before['state'] ?? '', 'button' => $button, 'answers' => $answers]);
}

/** The form answers of a right setup. */
function quick_form(array $set = []): array
{
    return $set + [
        'name' => 'Magic Grid',
        'login_uri' => 'login.example.org:8102',
        'avatar' => 'Ada Love',
        'password' => 'secret123',
        'password2' => 'secret123',
        'email' => 'ada@example.org',
    ];
}

/** The scenario up to a screen: the types, then the type chosen, then the form, then the options. */
function quick_until(Machine $machine, string $screen, string $type = 'grid', array $form = [], array $options = []): array
{
    $reply = quick_step($machine, null);
    if ($screen === 'menu') {
        return $reply;
    }
    $reply = quick_step($machine, $reply, ['type' => $type]);
    if ($screen === 'form') {
        return $reply;
    }
    $reply = quick_step($machine, $reply, quick_form($form));
    if ($screen === 'checks') {
        return $reply;
    }

    return quick_step($machine, $reply, $options);
}

/** The work that needs a machine, done by doubles: the databases are fine, the account is made, the grid says who it is, the instances start. */
function quick_doubles(array $set = []): void
{
    QuickSetup::through(
        $set + [
            'database' => static fn(): int => DatabaseService::OK,
            'account' => static fn(): bool => true,
            'says' => static fn(string $host, int $port): array => ['gridname' => 'Remote World', 'gridnick' => 'remote'],
            'region' => [
                'remoteHas' => static fn(string $name): ?bool => false,
                'freePlace' => static fn(int $x, int $y, ?string $ignored): array => [$x, $y, true],
            ],
        ],
    );
    InstanceControl::runThrough(static fn(): array => [0, '']);
}

/** The files of the distribution a grid is made of, in the fake core of a machine. */
function quick_core(Machine $machine): void
{
    $bin = $machine->core . '/bin';
    @mkdir("$bin/config-include", 0o755, true);
    file_put_contents("$bin/OpenSimDefaults.ini", "[Startup]\n");
    file_put_contents("$bin/OpenSim.ini", "[Startup]\n");
    foreach (['GridHypergrid', 'Grid', 'GridCommon', 'FlotsamCache', 'osslDefaultEnable'] as $name) {
        file_put_contents("$bin/config-include/$name.ini", "[OSSL]\n");
    }
    file_put_contents("$bin/config-include/osslEnable.ini.example", "[OSSL]\n    AllowOSFunctions = false\n");
}

test('advertises setup capabilities', function () {
    $machine = new Machine();
    $response = (new Api($machine->environment))->quickSetup(quick_answers());
    $attributes = json_decode($response->body(), true)['data']['attributes'];
    expect($attributes['capabilities'])->toContain('prepare', 'apply_prepared', 'existing_region')
        ->and(is_dir($machine->etc . '/grids/magic_grid'))->toBeFalse();
});

describe('existing region setup', function () {
    beforeEach(function () {
        quick_doubles(['region' => [
            'remoteHas' => static fn(): bool => true,
            'registeredUuid' => static fn(): string => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        ]]);
    });
    afterEach(function () {
        QuickSetup::through();
        InstanceControl::runThrough(null);
    });

    test('plans existing region', function () {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers([
            'type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]));
        expect($result['valid'])->toBeTrue()
            ->and($result['identity'])->toBe('remote.imported')
            ->and($result['setup']['simulators'][0]['regions'][0])->toMatchArray([
                'name' => 'Existing Garden', 'uuid' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                'location' => '120,130', 'port' => 9101,
            ]);
    });

    test('prepares existing region', function () {
        $machine = new Machine();
        $source = file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini');
        $menu = QuickSetup::next($machine->environment, ['mode' => 'prepare']);
        $form = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $menu['state'], 'answers' => ['type' => 'simulator', 'existing_region' => $source],
        ]);
        expect(array_column($form['fields'], 'value', 'name')['name'])->toBe('Existing Garden')
            ->and(array_column($form['fields'], 'label', 'name')['database_name'])->toBe('Existing database');
        $review = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $form['state'],
            'answers' => quick_answers(['type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db']),
        ]);
        $database = QuickSetup::next($machine->environment, ['mode' => 'prepare', 'state' => $review['state']]);
        $prepared = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $database['state'], 'answers' => array_column($database['fields'], 'value', 'name'),
        ]);
        expect($prepared['action'])->toBe('prepared')
            ->and($prepared['configuration']['answers']['existing_region'])->toBe($source)
            ->and($prepared['configuration']['setup']['simulators'][0]['regions'][0]['source'])->toBe($source)
            ->and(is_dir($machine->etc . '/grids/remote'))->toBeFalse();
    });

    test('stages existing region', function () {
        quick_doubles([
            'database' => static fn(): int => throw new RuntimeException('unexpected database provisioning'),
            'account' => static fn(): bool => throw new RuntimeException('unexpected account creation'),
            'region' => ['registeredUuid' => static fn(): string => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'],
        ]);
        InstanceControl::runThrough(static fn(): array => throw new RuntimeException('unexpected startup'));
        $source = new Machine();
        $answers = quick_answers([
            'type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]);
        $planned = QuickSetup::plan($source->environment, $answers);
        $target = new Machine();
        $done = QuickSetup::next($target->environment, ['configuration' => [
            'version' => 1, 'identity' => $planned['identity'], 'answers' => $answers, 'setup' => $planned['setup'],
        ]]);
        expect($done['action'])->toBe('quit')
            ->and($done['message'])->toContain('disabled')
            ->and(file_get_contents($target->etc . '/grids/remote/sims/remote.imported/regions/ExistingGarden.ini'))
            ->toBe(file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'))
            ->and(glob($target->etc . '/opensim.d/remote.imported.ini'))->toBe([]);
        $again = QuickSetup::next($target->environment, ['configuration' => [
            'version' => 1, 'identity' => $planned['identity'], 'answers' => $answers, 'setup' => $planned['setup'],
        ]]);
        expect($again['action'])->toBe('quit')
            ->and(file_get_contents($target->etc . '/grids/remote/sims/remote.imported/regions/ExistingGarden.ini'))
            ->toBe(file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'));
        $path = $target->etc . '/grids/remote/sims/remote.imported/regions/ExistingGarden.ini';
        $edited = str_replace('preserve-me', 'locally-edited', file_get_contents($path));
        file_put_contents($path, $edited);
        $conflict = QuickSetup::next($target->environment, ['configuration' => [
            'version' => 1, 'identity' => $planned['identity'], 'answers' => $answers, 'setup' => $planned['setup'],
        ]]);
        expect($conflict['action'])->toBe('abort')
            ->and($conflict['error'])->toContain('target region')
            ->and(file_get_contents($path))->toBe($edited);
    });

    test('rejects conflicting UUID', function () {
        quick_doubles(['region' => ['registeredUuid' => static fn(): string => 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff']]);
        $result = QuickSetup::plan((new Machine())->environment, quick_answers([
            'type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]));
        expect($result['valid'])->toBeFalse()
            ->and($result['problems']['existing_region'])->toContain('another UUID');
    });

    test('rejects UUID/name conflict', function () {
        quick_doubles(['region' => [
            'registeredUuid' => static fn(): bool => false,
            'registeredName' => static fn(): string => 'Another Garden',
        ]]);
        $result = QuickSetup::plan((new Machine())->environment, quick_answers([
            'type' => 'simulator', 'name' => 'Imported', 'database_name' => 'existing_db',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]));
        expect($result['valid'])->toBeFalse()
            ->and($result['problems']['existing_region'])->toContain('another name');
    });

    test('requires existing database', function () {
        $result = QuickSetup::plan((new Machine())->environment, quick_answers([
            'type' => 'simulator', 'name' => 'Imported',
            'existing_region' => file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini'),
        ]));
        expect($result['valid'])->toBeFalse()
            ->and($result['problems'])->toHaveKey('database_name');
    });
});

describe('setup preparation', function () {
    beforeEach(fn() => quick_doubles());
    afterEach(function () {
        QuickSetup::through();
        InstanceControl::runThrough(null);
    });

    test('collects database answers without provisioning', function () {
        $machine = new Machine();
        $called = false;
        quick_doubles(['database' => static function () use (&$called): int {
            $called = true;
            return DatabaseService::OK;
        }]);
        $review = quick_until($machine, 'review');
        $database = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $review['state'],
        ]);
        expect($database)->toMatchArray(['action' => 'screen', 'kind' => 'form', 'title' => 'Database']);
        $answers = array_column($database['fields'], 'value', 'name');
        $answers['admin_user'] = 'opensim_admin';
        $answers['admin_password'] = 'admin-secret';
        $done = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $database['state'], 'answers' => $answers,
        ]);

        expect($done['action'])->toBe('prepared')
            ->and($done['configuration']['answers'])->toMatchArray([
                'type' => 'grid', 'name' => 'Magic Grid', 'password' => 'secret123',
                'admin_user' => 'opensim_admin', 'admin_password' => 'admin-secret',
            ])
            ->and($done['configuration']['setup']['grid']['nick'])->toBe('magic_grid')
            ->and($called)->toBeFalse()
            ->and(is_dir($machine->etc . '/grids/magic_grid'))->toBeFalse();
    });

    test('prepares the instance identity', function (string $type, string $expected) {
        $machine = new Machine();
        $review = quick_until($machine, 'review', $type);
        $database = QuickSetup::next($machine->environment, ['mode' => 'prepare', 'state' => $review['state']]);
        $done = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $database['state'],
            'answers' => array_column($database['fields'], 'value', 'name'),
        ]);
        expect($done['configuration']['identity'])->toBe($expected);
    })->with(['grid' => ['grid', 'magic_grid'], 'standalone' => ['standalone', 'magic_grid.magic_grid']]);

    test('applies confirmed ports', function () {
        $source = new Machine();
        $source->processes->listening = [9100];
        $review = quick_until($source, 'review');
        $database = QuickSetup::next($source->environment, ['mode' => 'prepare', 'state' => $review['state']]);
        $prepared = QuickSetup::next($source->environment, [
            'mode' => 'prepare', 'state' => $database['state'],
            'answers' => array_column($database['fields'], 'value', 'name'),
        ])['configuration'];
        $configuration = json_decode(json_encode($prepared), true);
        $target = new Machine();
        $done = QuickSetup::next($target->environment, ['configuration' => $configuration]);
        expect($done['action'])->toBe('quit')
            ->and(file_get_contents($target->etc . '/grids/magic_grid/sims/magic_grid.welcome.ini'))->toContain('9110')
            ->and(file_get_contents($target->etc . '/grids/magic_grid/sims/magic_grid.welcome/regions/Welcome.ini'))->toContain('9111')
            ->and($prepared['setup']['grid']['console_port'])->toBeInt()
            ->and($prepared['setup']['simulators'][0]['console_port'])->toBe(9114);
    });

    test('refuses a changed prepared location', function () {
        $source = new Machine();
        $review = quick_until($source, 'checks', 'simulator', ['name' => 'Garden']);
        $database = QuickSetup::next($source->environment, ['mode' => 'prepare', 'state' => $review['state']]);
        $configuration = QuickSetup::next($source->environment, [
            'mode' => 'prepare', 'state' => $database['state'],
            'answers' => array_column($database['fields'], 'value', 'name'),
        ])['configuration'];
        quick_doubles(['region' => [
            'remoteHas' => static fn(): bool => false,
            'freePlace' => static fn(int $x, int $y): array => [$x + 1, $y, true],
        ]]);
        $target = new Machine();
        $result = QuickSetup::next($target->environment, ['configuration' => $configuration]);
        expect($result['action'])->toBe('abort')
            ->and($result['error'])->toContain('location')
            ->and(is_dir($target->etc . '/grids/remote'))->toBeFalse();
    });

    test('rejects a changed prepared identity', function () {
        $machine = new Machine();
        $result = QuickSetup::next($machine->environment, ['configuration' => [
            'version' => 1, 'identity' => 'another_grid', 'answers' => quick_answers(),
            'setup' => QuickSetup::plan($machine->environment, quick_answers())['setup'],
        ]]);
        expect($result)->toMatchArray(['action' => 'abort'])
            ->and($result['error'])->toContain('identity')
            ->and(is_dir($machine->etc . '/grids/magic_grid'))->toBeFalse();
    });

    test('rejects an invalid prepared port', function () {
        $machine = new Machine();
        $setup = QuickSetup::plan($machine->environment, quick_answers())['setup'];
        $setup['simulators'][0]['http_port'] = 0;
        $result = QuickSetup::next($machine->environment, ['configuration' => [
            'version' => 1, 'identity' => 'magic_grid', 'answers' => quick_answers(), 'setup' => $setup,
        ]]);
        expect($result['action'])->toBe('abort')
            ->and(is_dir($machine->etc . '/grids/magic_grid'))->toBeFalse();
    });

    test('keeps preparation cancellable', function () {
        $machine = new Machine();
        $review = quick_until($machine, 'review');
        $database = QuickSetup::next($machine->environment, ['mode' => 'prepare', 'state' => $review['state']]);
        $back = QuickSetup::next($machine->environment, [
            'mode' => 'prepare', 'state' => $database['state'], 'button' => 'back',
        ]);
        expect($back['kind'])->toBe('review')
            ->and($back['buttons']['next'])->toBe('Continue')
            ->and(QuickSetup::next($machine->environment, [
                'mode' => 'prepare', 'state' => $database['state'], 'button' => 'quit',
            ])['action'])->toBe('abort')
            ->and(is_dir($machine->etc . '/grids/magic_grid'))->toBeFalse();
    });

    test('rejects an unknown mode before applying setup', function () {
        expect(fn() => QuickSetup::next((new Machine())->environment, ['mode' => 'typo']))
            ->toThrow(InvalidArgumentException::class, 'Unknown setup mode');
    });

    test('keeps answers over configured defaults', function () {
        $previous = getenv('DEFAULT_OWNER_NAME');
        putenv('DEFAULT_OWNER_NAME=Default Resident');
        try {
            $machine = new Machine();
            $options = quick_until($machine, 'checks');
            $form = quick_step($machine, $options, [], 'back');
            expect(array_column($form['fields'], 'value', 'name')['avatar'])->toBe('Ada Love');
        } finally {
            putenv($previous === false ? 'DEFAULT_OWNER_NAME' : 'DEFAULT_OWNER_NAME=' . $previous);
        }
    });
});

describe('quick setup, the scenario', function () {
    beforeEach(fn() => quick_doubles());
    afterEach(function () {
        QuickSetup::through();
        InstanceControl::runThrough(null);
    });

    test('starts with what can be set up, the choices not implemented said and kept in the list', function () {
        $reply = quick_step(new Machine(), null);

        expect($reply)
            ->toMatchArray(['action' => 'screen', 'kind' => 'menu', 'name' => 'type', 'title' => 'OpenSim setup'])
            ->and(array_column($reply['items'], 'note', 'key'))
            ->toBe(['grid' => '', 'standalone' => '', 'simulator' => '', 'speculoos' => '', 'osgrid' => ''])
            ->and($reply['buttons'])
            ->toBe(['next' => 'OK', 'back' => 'Quit']);
    });

    test('keeps the menu, and tells why, for a choice that is not one that can be set up', function () {
        $machine = new Machine();
        $reply = quick_step($machine, quick_step($machine, null), ['type' => 'nothing']);

        expect($reply)
            ->toMatchArray(['kind' => 'menu', 'value' => 'nothing'])
            ->and($reply['notice'])
            ->toStartWith('Not implemented yet: only Grid, Standalone');
    });

    test('goes on with the questions of the type, with what the engine proposes', function () {
        $machine = new Machine();
        $grid = quick_until($machine, 'form');
        $standalone = quick_until($machine, 'form', 'standalone');

        expect($grid)
            ->toMatchArray(['kind' => 'form', 'title' => 'Grid', 'notice' => ''])
            ->and(array_column($grid['fields'], 'label', 'name'))
            ->toBe(['name' => 'Grid name', 'login_uri' => 'Login URI', 'avatar' => 'Avatar name', 'password' => 'Password', 'password2' => 'Password again', 'email' => 'Email', 'private_port' => 'Grid private port', 'grid_console_port' => 'Grid console port', 'http_port' => 'Simulator HTTP port', 'console_port' => 'Simulator console port', 'region_port' => 'Region UDP port'])
            ->and(array_column($grid['fields'], 'secret', 'name'))
            ->toMatchArray(['password' => true, 'password2' => true, 'name' => false])
            ->and(array_column($grid['fields'], 'value', 'name')['login_uri'])
            ->toContain(':')
            ->and($standalone['title'])
            ->toBe('Standalone')
            ->and($standalone['fields'][0]['label'])
            ->toBe('Region name');
    });

    test('ends with an error when the person quits, at the first screen or any other', function () {
        $machine = new Machine();

        expect(quick_step($machine, quick_step($machine, null), [], 'back'))
            ->toBe(['action' => 'abort', 'error' => 'Cancelled'])
            ->and(quick_step($machine, quick_until($machine, 'form'), [], 'quit'))
            ->toBe(['action' => 'abort', 'error' => 'Cancelled']);
    });

    test('starts again at the first screen for a state that is not one', function () {
        $reply = QuickSetup::next((new Machine())->environment, ['state' => 'not-a-state', 'answers' => ['type' => 'grid']]);

        expect($reply['kind'])->toBe('menu');
    });

    test('shows the form again with what is wrong, everything at once, and what was typed', function () {
        $machine = new Machine();
        $reply = quick_step($machine, quick_until($machine, 'form'), quick_form(['avatar' => 'Ada', 'password2' => 'other']));

        expect($reply['kind'])
            ->toBe('form')
            ->and($reply['notice'])
            ->toBe("Error: Avatar name invalid (First Last)\nError: Passwords differ")
            ->and(array_column($reply['fields'], 'value', 'name'))
            ->toMatchArray(['avatar' => 'Ada', 'name' => 'Magic Grid', 'password' => '', 'password2' => ''])
            ->and($reply['buttons']['back'])
            ->toBe('Back');
    });

    test('replaces what the setup corrects in the form, and tells it, for the person to confirm', function () {
        $machine = new Machine();
        $reply = quick_step($machine, quick_until($machine, 'form'), quick_form(['avatar' => 'José García']));

        expect($reply['notice'])
            ->toBe('Error: Avatar name transliterated')
            ->and(array_column($reply['fields'], 'value', 'name')['avatar'])
            ->toBe('Jose Garcia')
            ->and(quick_step($machine, $reply, quick_form(['avatar' => 'Jose Garcia']))['kind'])
            ->toBe('checks');
    });

    test('goes back one screen at a time, keeping the answers', function () {
        $machine = new Machine();
        $options = quick_until($machine, 'checks');
        $form = quick_step($machine, $options, [], 'back');
        $menu = quick_step($machine, $form, quick_form(['avatar' => 'Grace Hopper']), 'back');

        expect($form['kind'])
            ->toBe('form')
            ->and(array_column($form['fields'], 'value', 'name')['avatar'])
            ->toBe('Ada Love')
            ->and($menu)
            ->toMatchArray(['kind' => 'menu', 'value' => 'grid'])
            ->and(array_column(quick_step($machine, $menu, ['type' => 'grid'])['fields'], 'value', 'name')['avatar'])
            ->toBe('Grace Hopper');
    });

    test('asks for the options with their proposals, then shows everything the setup will do', function () {
        $machine = new Machine();
        $options = quick_until($machine, 'checks');
        $review = quick_step($machine, $options, ['ossl-extended' => true, 'economy-gloebit' => true, 'hypergrid' => false]);

        expect($options['kind'])
            ->toBe('checks')
            ->and($options['title'])
            ->toBe('Options')
            ->and(array_column($options['items'], 'checked', 'name'))
            ->toBe(['ssl' => false, 'hypergrid' => true, 'helpers' => true, 'search' => true, 'economy-gloebit' => false, 'ossl-extended' => true])
            ->and(array_column($options['items'], 'note', 'name')['ssl'])
            ->toBe('not implemented')
            ->and($review)
            ->toMatchArray(['kind' => 'review', 'title' => 'Review'])
            ->and($review['buttons']['next'])
            ->toBe('Install')
            ->and(array_column(array_filter($review['rows'], static fn(array $r): bool => $r['section'] === 'options'), 'value', 'label'))
            ->toMatchArray(['Hypergrid' => 'no', 'Economy: Gloebit' => 'yes', 'Extended OSSL' => 'yes']);
        expect(quick_step($machine, $review, [], 'back')['kind'])->toBe('checks');
    });

    test('makes the folders and the files of a grid and of its first simulator when the review is confirmed', function () {
        $machine = new Machine();
        quick_core($machine);
        $review = quick_until($machine, 'review', options: ['ossl-extended' => true, 'search' => true, 'hypergrid' => true]);
        $done = quick_step($machine, $review);

        expect($done['action'])
            ->toBe('quit')
            ->and($done['message'])
            ->toContain('Grid name:  Magic Grid', 'Login URI:  http://login.example.org:8102', 'Avatar:     Ada Love')
            ->toContain('The grid and its simulator are running.')
            ->not->toContain('MoneyServer')
            ->not->toContain('chgrp');
        preg_match('/Written in (\S+)\./', $done['message'], $m);
        $folder = $m[1];

        expect($folder)
            ->toStartWith($machine->etc)
            ->and(is_dir($folder))
            ->toBeTrue()
            ->and(glob("$folder/Robust*.ini"))
            ->not->toBe([])
            ->and((string) file_get_contents("$folder/Robust.HG.ini"))
            ->toContain('Port = 8102')
            ->and(glob("$folder/sims/*.ini"))
            ->toHaveCount(1)
            ->and((string) file_get_contents("$folder/config-include/osslEnable.ini"))
            ->toContain('osslParcelO')
            ->and(implode("\n", array_map('file_get_contents', array_merge(glob("$folder/*.ini"), glob("$folder/sims/*.ini")))))
            ->toContain('https://2do.directory/helpers/query.php');
    });

    test('makes a standalone, with no Robust, and the account is left to its first start', function () {
        $machine = new Machine();
        $done = quick_step($machine, quick_until($machine, 'review', 'standalone', ['name' => 'Solo World', 'login_uri' => 'solo.example.org:9100']));

        preg_match('/Written in (\S+)\./', $done['message'], $m);

        expect($done['action'])
            ->toBe('quit')
            ->and($done['message'])
            ->toContain('The grid and its simulator are running.')
            ->and(glob("{$m[1]}/Robust*.ini"))
            ->toBe([])
            ->and(glob("{$m[1]}/sims/*.ini"))
            ->toHaveCount(1);
    });

    test('says it cannot write, and writes nothing, when the place is not one it can write in', function () {
        $machine = new Machine();
        quick_core($machine);
        $review = quick_until($machine, 'review');
        // The folder of the grids is a file: nothing can be made in it
        rename("{$machine->etc}/grids", "{$machine->etc}/grids.kept");
        file_put_contents("{$machine->etc}/grids", 'not a folder');

        $done = quick_step($machine, $review);

        expect($done['action'])
            ->toBe('abort')
            ->and($done['error'])
            ->toContain('Cannot write in', "{$machine->etc}/grids");
    });

    test('puts the helpers on, and says so, when Gloebit needs them', function () {
        $machine = new Machine();
        $options = quick_until($machine, 'checks');

        $again = quick_step($machine, $options, ['helpers' => false, 'economy-gloebit' => true]);

        expect($again)
            ->toMatchArray(['kind' => 'checks', 'notice' => 'Economy: Gloebit needs the helpers: Helpers is on.'])
            ->and(array_column($again['items'], 'checked', 'name'))
            ->toMatchArray(['helpers' => true, 'economy-gloebit' => true])
            ->and(quick_step($machine, $again, ['helpers' => true, 'economy-gloebit' => true])['kind'])
            ->toBe('review');
    });

    test('asks the database again, with what the server said, when it cannot be reached or made, and goes on with the administrator', function () {
        $machine = new Machine();
        quick_core($machine);
        $calls = 0;
        quick_doubles([
            'database' => function (GridPlan $db, UiInterface $ui) use (&$calls): int {
                if ($calls++ === 0) {
                    $ui->error('Cannot reach the database server localhost: refused');

                    return DatabaseService::RETRY;
                }

                return DatabaseService::OK;
            },
        ]);

        $asked = quick_step($machine, quick_until($machine, 'review'));
        $done = quick_step($machine, $asked, ['database_host' => 'db.example.org', 'admin_user' => 'root', 'admin_password' => 'secret']);

        expect($asked)
            ->toMatchArray(['kind' => 'form', 'title' => 'Database'])
            ->and($asked['notice'])
            ->toContain('Cannot reach the database server localhost: refused')
            ->and(array_column($asked['fields'], 'name'))
            ->toBe(['database_host', 'database_name', 'database_user', 'database_password', 'admin_user', 'admin_password'])
            ->and($done['action'])
            ->toBe('quit');
    });

    test('uses database administrator credentials sent with the review confirmation', function () {
        $machine = new Machine();
        quick_core($machine);
        quick_doubles([
            'database' => static function (GridPlan $db, UiInterface $ui): int {
                expect($db->dbHost)->toBe('localhost')
                    ->and($db->dbUser)->toBe('opensim')
                    ->and($db->dbPass)->toBe('app-secret');
                $ui->error('Cannot reach the database server localhost: refused');

                return DatabaseService::RETRY;
            },
        ]);

        $asked = quick_step(
            $machine,
            quick_until($machine, 'review'),
            [
                'database_host' => 'localhost',
                'database_user' => 'opensim',
                'database_password' => 'app-secret',
                'admin_user' => 'opensim_admin',
                'admin_password' => 'admin-secret',
            ],
        );
        $values = array_column($asked['fields'], 'value', 'name');

        expect($asked)
            ->toMatchArray(['kind' => 'form', 'title' => 'Database'])
            ->and($values['admin_user'])
            ->toBe('opensim_admin')
            ->and($values['admin_password'])
            ->toBe('admin-secret');
    });

    test('stops, and says why, when the database cannot be made or the account of the avatar cannot be', function () {
        $machine = new Machine();
        quick_core($machine);
        quick_doubles([
            'database' => static function (GridPlan $db, UiInterface $ui): int {
                $ui->error('Could not create the database: denied');

                return DatabaseService::ABORT;
            },
        ]);
        $stopped = quick_step($machine, quick_until($machine, 'review'));
        quick_doubles(['account' => static function (Grid $grid, SimulatorPlan $sim, UiInterface $ui): bool {
            $ui->error('Robust did not make the account');

            return false;
        }]);
        $noAccount = quick_step($machine, quick_until($machine, 'review'));

        expect($stopped['action'])
            ->toBe('abort')
            ->and($stopped['error'])
            ->toContain('Could not create the database: denied', 'nothing was changed')
            ->and($noAccount)
            ->toBe(['action' => 'abort', 'error' => 'Robust did not make the account']);
    });

    test('copies the assets and the library of the core into the data folder of the grid, once, and points the config there', function () {
        $machine = new Machine();
        quick_core($machine);
        $bin = $machine->core . '/bin';
        @mkdir("$bin/assets/Sounds", 0o755, true);
        @mkdir("$bin/inventory", 0o755, true);
        file_put_contents("$bin/assets/AssetSets.xml", '<Nini/>');
        file_put_contents("$bin/assets/Sounds/click.ogg", 'sound');
        file_put_contents("$bin/inventory/Libraries.xml", '<Nini/>');
        $done = quick_step($machine, quick_until($machine, 'review'));
        preg_match('/Written in (\S+)\./', $done['message'], $m);
        $robust = (string) file_get_contents(glob("{$m[1]}/Robust*.ini")[0]);
        $data = $machine->root . '/data/magic_grid';

        expect($done['action'])
            ->toBe('quit')
            ->and(is_file("$data/assets/AssetSets.xml"))
            ->toBeTrue()
            ->and(is_file("$data/assets/Sounds/click.ogg"))
            ->toBeTrue()
            ->and(is_file("$data/inventory/Libraries.xml"))
            ->toBeTrue()
            ->and($robust)
            ->toContain('${Const|DataDirectory}/assets/AssetSets.xml', '${Const|DataDirectory}/inventory/Libraries.xml')
            ->and(is_dir("{$m[1]}/assets"))
            ->toBeFalse();
    });

    test('sets up a simulator that joins the grid of a preset: what the preset says is shown and not asked, the owner is an account of that grid', function () {
        $machine = new Machine();
        quick_core($machine);
        $form = quick_until($machine, 'form', 'speculoos');

        expect($form['title'])
            ->toBe('Speculoos')
            ->and(array_column($form['fields'], 'readonly', 'name'))
            ->toBe(['preset_grid' => true, 'preset_login' => true, 'preset_hypergrid' => true, 'name' => false, 'avatar' => false, 'http_port' => false, 'console_port' => false, 'region_port' => false])
            ->and(array_column($form['fields'], 'value', 'name')['name'])
            ->toBe('Welcome');
        $wrong = quick_step($machine, $form, ['name' => 'Plaza', 'avatar' => 'Ada']);
        $review = quick_step($machine, $form, ['name' => 'Plaza', 'avatar' => 'Ada Love']);
        $done = quick_step($machine, $review);

        expect($wrong['notice'])
            ->toBe('Error: Avatar name invalid (First Last)')
            ->and($review['kind'])
            ->toBe('review')
            ->and(array_column($review['rows'], 'value', 'label')['Estate owner'])
            ->toBe('Ada Love (an account of the grid)')
            ->and(array_column($review['rows'], 'section'))
            ->not->toContain('options')
            ->and($done['action'])
            ->toBe('quit')
            ->and($done['message'])
            ->toContain('Avatar:     Ada Love', 'The simulator is running.')
            ->and(glob("{$machine->etc}/grids/speculoos/sims/*.ini"))
            ->toHaveCount(1);
        expect(quick_step($machine, $review, [], 'back')['kind'])->toBe('form');
    });

    test('sets up a simulator that joins a grid given by its address, which says who it is', function () {
        $machine = new Machine();
        quick_core($machine);
        $form = quick_until($machine, 'form', 'simulator');
        $missing = quick_step($machine, $form, ['login_uri' => '', 'name' => 'Plaza', 'avatar' => 'Ada Love']);
        $review = quick_step($machine, $form, ['login_uri' => 'remote.example.org:8002', 'private_port' => '', 'name' => 'Plaza', 'avatar' => 'Ada Love']);
        $done = quick_step($machine, $review);

        expect($form['title'])
            ->toBe('Simulator (join other grid)')
            ->and(array_column($form['fields'], 'name'))
            ->toBe(['login_uri', 'private_port', 'name', 'avatar', 'http_port', 'console_port', 'region_port'])
            ->and($missing['notice'])
            ->toBe('Error: Login URI of the grid required')
            ->and(array_column($review['rows'], 'value', 'label')['Grid'])
            ->toBe('Remote World (remote)')
            ->and($done['action'])
            ->toBe('quit')
            ->and($done['message'])
            ->toContain('Grid name:  Remote World', 'Login URI:  http://remote.example.org:8002')
            ->and(glob("{$machine->etc}/grids/remote/sims/*.ini"))
            ->toHaveCount(1);
    });

    test('is asked and answered through the API like the other operations', function () {
        $router = new Router(new Api((new Machine())->environment));
        $first = json_decode($router->post('/setups/steps', ['data' => ['type' => 'steps', 'attributes' => []]])->body(), true)['data'];
        $next = json_decode(
            $router->post('/setups/steps', ['data' => ['type' => 'steps', 'attributes' => ['state' => $first['attributes']['state'], 'answers' => ['type' => 'grid']]]])->body(),
            true,
        )['data'];

        expect($first['type'])
            ->toBe('steps')
            ->and($first['attributes']['kind'])
            ->toBe('menu')
            ->and($next['attributes']['kind'])
            ->toBe('form');
    });
});
