<?php

declare(strict_types=1);

/**
 * The resources of the machine, of a grid, of the application as JSON:API documents, what is written, the errors as problems, and the
 * contract that says so: checked against the models.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Contract;
use OpenSim\Api\Problem;
use OpenSim\Api\Response;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Grid;
use OpenSim\Scheduling\Scheduler;
use OpenSim\Scheduling\Task;
use OpenSim\Testing\ClockFake;
use OpenSim\Testing\Machine;

/** An API over the test machine, the config of its grids being the one the test gives. */
function machine_api(?GridConfig $config = null, ?Scheduler $scheduler = null): Api
{
    return new Api(
        (new Machine())->environment,
        $scheduler,
        $config === null ? null : static fn(Grid $grid): GridConfig => $config,
    );
}

/** The attributes of the first resource of a list. */
function first_attributes(Response $response): array
{
    return $response->document['data'][0]['attributes'];
}

describe('The API', function () {
    test('lists the resources of a type in a JSON:API document, with the page and what was applied', function () {
        $api = machine_api();
        $response = $api->list('services', [
            'filter' => ['served' => 'yes'],
            'sort' => '-name',
            'page' => ['number' => 1, 'size' => 1],
        ]);

        expect($response->status)
            ->toBe(200)
            ->and($response->headers['Content-Type'])
            ->toBe(Response::JSON_API)
            ->and($response->document['data'])
            ->toHaveCount(1)
            ->and($response->document['data'][0])
            ->toMatchArray(['type' => 'services', 'id' => 'demo_grid', 'links' => ['self' => '/services/demo_grid']])
            ->and($response->document['data'][0]['attributes']['key'])
            ->toBe('grid')
            ->and($response->document['data'][0]['attributes'])
            ->not->toHaveKey('id')
            ->and($response->document['meta'])
            ->toMatchArray([
                'total' => 2,
                'page' => 1,
                'perPage' => 1,
                'sort' => 'name desc',
                'filters' => ['served' => 'yes'],
            ])
            ->and(json_decode($response->body(), true)['data'][0]['type'])
            ->toBe('services');
    });

    test('reads one resource by its id', function () {
        $api = machine_api();

        $grid = $api->get('grids', 'demo');
        $simulator = $api->get('simulators', 'alpha');

        expect($grid->status)
            ->toBe(200)
            ->and($grid->document['data'])
            ->toMatchArray(['type' => 'grids', 'id' => 'demo'])
            ->and($grid->document['data']['attributes']['name'])
            ->toBe('Demo')
            ->and($simulator->status)
            ->toBe(200)
            ->and($simulator->document['data']['type'])
            ->toBe('simulators');
    });

    test('tells what is not there as a problem, RFC 9457', function () {
        $api = machine_api();

        $missing = $api->get('grids', 'nowhere');
        $kind = $api->list('nothings');
        $noGrid = $api->list('avatars', [], grid: 'nowhere');
        $outside = $api->list('avatars');

        expect($missing->status)
            ->toBe(404)
            ->and($missing->headers['Content-Type'])
            ->toBe(Problem::CONTENT_TYPE)
            ->and($missing->document)
            ->toMatchArray([
                'title' => 'Not found',
                'status' => 404,
                'instance' => '/grids/nowhere',
                'type' => 'urn:opensim:problem:not-found',
            ])
            ->and($kind->status)
            ->toBe(404)
            ->and($noGrid->status)
            ->toBe(404)
            ->and($noGrid->document['detail'])
            ->toContain("grid called 'nowhere'")
            ->and($outside->status)
            ->toBe(404);
    });

    test(
        'tells an exception as the problem it stands for, without the message of a failure of the server',
        function () {
            expect(Problem::from(new InvalidArgumentException('a name'))->status)
                ->toBe(400)
                ->and(Problem::from(ChannelUnavailable::for('read the avatars', 'no database'))->toArray())
                ->toMatchArray([
                    'status' => 503,
                    'title' => 'Not available',
                    'detail' => 'cannot read the avatars: no database',
                ])
                ->and(Problem::from(new RuntimeException('/secret/path password'))->toArray())
                ->toBe(['type' => 'about:blank', 'title' => 'Internal error', 'status' => 500])
                ->and(json_decode(Problem::from(new InvalidArgumentException('x'), '/grids')->json(), true)['instance'])
                ->toBe('/grids');
        },
    );

    test('turns the query of JSON:API into the options of the models', function () {
        expect(
            Api::options([
                'search' => 'demo',
                'sort' => '-name',
                'filter' => ['remote' => 'no'],
                'page' => ['number' => '2', 'size' => '10'],
            ]),
        )
            ->toBe([
                'search' => 'demo',
                'sort' => 'name desc',
                'filters' => ['remote' => 'no'],
                'page' => 2,
                'perPage' => 10,
            ])
            ->and(Api::options(['sort' => 'port']))
            ->toBe(['sort' => 'port'])
            ->and(Api::options([]))
            ->toBe([])
            ->and(Api::path('avatars', 'a b', 'my grid'))
            ->toBe('/grids/my%20grid/avatars/a%20b');
    });
});

describe('The API, for the resources of a grid', function () {
    test('lists and reads the avatars of a grid, in the database of its services', function () {
        Connections::using(static fn() => avatar_creation_database());
        $api = machine_api(avatar_grid());

        $list = $api->list('avatars', ['search' => 'ada'], grid: 'demo');
        $one = $api->get('avatars', '11111111-1111-1111-1111-111111111111', grid: 'demo');

        expect($list->status)
            ->toBe(200)
            ->and($list->document['data'][0])
            ->toMatchArray([
                'type' => 'avatars',
                'id' => '11111111-1111-1111-1111-111111111111',
                'links' => ['self' => '/grids/demo/avatars/11111111-1111-1111-1111-111111111111'],
            ])
            ->and(first_attributes($list)['name'])
            ->toBe('Ada Love')
            ->and($list->document['meta']['search'])
            ->toBe('ada')
            ->and($one->document['data']['attributes']['email'])
            ->toBe('ada@example.org');
    });

    test('makes an avatar, changes it, and deletes nothing when no channel does', function () {
        $pdo = avatar_creation_database();
        Connections::using(static fn() => $pdo);
        $api = machine_api(avatar_grid());

        $made = $api->create(
            'avatars',
            ['name' => 'Api Person', 'email' => 'api@example.org', 'password' => 'secret'],
            grid: 'demo',
        );
        $id = $made->document['data']['id'];
        $changed = $api->update('avatars', $id, ['level' => 5, 'active' => false, 'password' => 'other'], grid: 'demo');
        $taken = $api->create('avatars', ['name' => 'Api Person', 'password' => 'x'], grid: 'demo');
        $unknown = $api->update('avatars', $id, ['nonsense' => 1], grid: 'demo');
        $deleted = $api->delete('avatars', $id, grid: 'demo');

        expect($made->status)
            ->toBe(201)
            ->and($made->headers['Location'])
            ->toBe("/grids/demo/avatars/$id")
            ->and($made->document['data']['attributes']['name'])
            ->toBe('Api Person')
            ->and($changed->status)
            ->toBe(200)
            ->and($changed->document['data']['attributes'])
            ->toMatchArray(['level' => 5, 'active' => false])
            ->and($taken->status)
            ->toBe(400)
            ->and($taken->document['detail'])
            ->toContain('exists already')
            ->and($unknown->status)
            ->toBe(400)
            ->and($deleted->status)
            ->toBe(503)
            ->and($api->update('avatars', 'nobody', ['level' => 1], grid: 'demo')->status)
            ->toBe(404);
    });

    test('enables and disables what can be, and says nothing makes what the setup makes', function () {
        $api = machine_api();

        $off = $api->update('simulators', 'alpha', ['enabled' => false]);
        $on = $api->update('simulators', 'alpha', ['enabled' => true]);
        $made = $api->create('grids', ['name' => 'New']);

        expect($off->status)
            ->toBe(200)
            ->and($off->document['data']['attributes']['enabled'])
            ->toBeFalse()
            ->and($on->document['data']['attributes']['enabled'])
            ->toBeTrue()
            ->and($made->status)
            ->toBe(503)
            ->and($made->document['detail'])
            ->toContain('made by the setup');
    });

    test('lists the regions, the parcels and the estates of a grid', function () {
        Connections::using(static fn() => region_database());
        $regions = machine_api(region_config())->list('regions', [], grid: 'demo');

        [$alpha, $beta] = parcel_databases();
        parcels_in([$alpha, $beta]);
        $parcels = machine_api(parcel_config())->list('parcels', ['sort' => '-dwell'], grid: 'demo');

        $estates = machine_api(estate_world())->list('estates', [], grid: 'demo');

        expect($regions->document['meta']['total'])
            ->toBe(3)
            ->and($regions->document['data'][0]['type'])
            ->toBe('regions')
            ->and(array_column(array_column($parcels->document['data'], 'attributes'), 'name'))
            ->toBe(['Harbour Front', 'The Garden', 'Kiosk'])
            ->and(array_column($estates->document['data'], 'id'))
            ->toBe(['alpha:101', 'beta:101', 'alpha:102']);
    });
});

describe('The API, for the application', function () {
    test('lists the tasks with their last run, reads one, and runs it now', function () {
        $clock = new ClockFake();
        $scheduler = new Scheduler(test_tmp() . '/api-cron-' . bin2hex(random_bytes(4)), $clock);
        $scheduler
            ->add(new Task('count', 3600, fn() => 'counted', 'Count'))
            ->add(new Task('broken', 60, fn() => throw new RuntimeException('no host')));
        $api = machine_api(scheduler: $scheduler);

        $before = $api->list('tasks');
        $run = $api->run('count');
        $after = $api->get('tasks', 'count');
        $failed = $api->run('broken');

        expect($before->document['data'])
            ->toHaveCount(2)
            ->and($before->document['data'][0])
            ->toMatchArray(['type' => 'tasks', 'id' => 'count'])
            ->and($before->document['data'][0]['attributes'])
            ->toMatchArray(['label' => 'Count', 'every' => 3600, 'lastRun' => null, 'due' => true])
            ->and($run->status)
            ->toBe(200)
            ->and($run->document['data']['attributes'])
            ->toMatchArray(['task' => 'count', 'status' => 'ran', 'message' => 'counted'])
            ->and($after->document['data']['attributes'])
            ->toMatchArray(['lastRun' => 1000, 'status' => 'ran', 'due' => false])
            ->and($failed->status)
            ->toBe(500)
            ->and($api->run('nothing')->status)
            ->toBe(404)
            ->and($api->get('tasks', 'nothing')->status)
            ->toBe(404)
            ->and(machine_api()->list('tasks')->document['data'])
            ->toBe([]);
    });

    test('gives the forms of the setup, without what only PHP can do', function () {
        $api = machine_api();

        $list = $api->list('forms');
        $grid = $api->get('forms', 'grid');
        $names = array_column($grid->document['data']['attributes']['fields'], 'name');

        expect(array_column($list->document['data'], 'id'))
            ->toBe(['grid', 'remote-grid', 'simulator', 'region'])
            ->and($names)
            ->toContain('name', 'nick', 'public_port')
            ->and($grid->document['data']['attributes']['fields'][0])
            ->not->toHaveKey('callback')
            ->and(json_encode($grid->document))
            ->not->toBeFalse()
            ->and($api->get('forms', 'nothing')->status)
            ->toBe(404);
    });
});

describe('The contract', function () {
    test(
        'is an OpenAPI 3.1 document with a list and a read for each resource, the writes that exist, and the problem',
        function () {
            $document = Contract::document();
            $paths = $document['paths'];

            expect($document['openapi'])
                ->toBe('3.1.0')
                ->and(array_keys($paths))
                ->toContain(
                    '/grids',
                    '/grids/{id}',
                    '/services',
                    '/grids/{grid}/avatars',
                    '/grids/{grid}/avatars/{id}',
                    '/grids/{grid}/estates',
                    '/grids/{grid}/destinations',
                    '/grids/{grid}/motd',
                    '/grids/{grid}/health',
                    '/grids/{grid}/search/register',
                    '/grids/{grid}/search/query',
                    '/grids/{grid}/currency.php',
                    '/grids/{grid}/landtool.php',
                    '/grids/{grid}/offline-messages/SaveMessage',
                    '/grids/{grid}/offline-messages/RetrieveMessages',
                    '/get_grid_info',
                    '/text-images',
                    '/fonts',
                    '/tasks',
                    '/tasks/{id}/runs',
                    '/forms/{id}',
                )
                ->and(array_keys($document['components']['schemas']))
                ->toBe([
                    'Problem',
                    'Grids',
                    'Simulators',
                    'Services',
                    'Archives',
                    'Avatars',
                    'Regions',
                    'Parcels',
                    'Estates',
                    'Assets',
                    'Destinations',
                    'Tasks',
                    'Forms',
                    'Fonts',
                ])
                ->and($paths['/services']['get']['parameters'])
                ->toContain([
                    'name' => 'filter[served]',
                    'in' => 'query',
                    'schema' => ['type' => 'string', 'enum' => ['yes', 'no']],
                ])
                ->and(array_keys($paths['/grids/{grid}/avatars']))
                ->toBe(['get', 'post'])
                ->and(array_keys($paths['/grids/{grid}/avatars/{id}']))
                ->toBe(['get', 'patch', 'delete'])
                ->and(array_keys($paths['/grids/{id}']))
                ->toBe(['get', 'patch'])
                ->and(array_keys($paths['/grids/{grid}/parcels/{id}']))
                ->toBe(['get'])
                ->and($paths['/grids/{id}']['get']['responses'])
                ->toHaveKeys(['200', '404'])
                ->and(json_encode($document))
                ->not->toBeFalse();
        },
    );

    test('is written in JSON and in YAML, the pages of the documentation being made from it', function () {
        expect(json_decode(Contract::json('2'), true)['info']['version'])
            ->toBe('2')
            ->and(Contract::yaml())
            ->toContain('openapi: 3.1.0')
            ->toContain('/grids/{grid}/avatars')
            ->and(Contract::attributes('services'))
            ->toHaveKey('kind');
    });

    test('knows every attribute the models give, and only them', function () {
        $machine = new Machine();
        $grid = Grid::find($machine->environment, 'demo');
        $details = [
            'grids' => $grid->detail(),
            'simulators' => $grid->simulator('alpha')->detail(),
            'services' => $grid->service('asset')->detail(),
            'archives' => archive_machine()->detail(),
        ];
        Connections::using(static fn() => avatar_creation_database());
        $details['avatars'] = first_attributes(machine_api(avatar_grid())->list('avatars', [], grid: 'demo'));
        Connections::using(static fn() => region_database());
        $details['regions'] = first_attributes(machine_api(region_config())->list('regions', [], grid: 'demo'));
        parcels_in(parcel_databases());
        $details['parcels'] = first_attributes(machine_api(parcel_config())->list('parcels', [], grid: 'demo'));
        $details['estates'] = first_attributes(machine_api(estate_world())->list('estates', [], grid: 'demo'));
        Connections::using(static fn() => asset_database());
        $details['assets'] = first_attributes(machine_api(asset_config())->list('assets', [], grid: 'demo'));
        $scheduler = new Scheduler(test_tmp() . '/api-cron-' . bin2hex(random_bytes(4)), new ClockFake());
        $scheduler->add(new Task('count', 60, fn() => null));
        $details['tasks'] = first_attributes(machine_api(scheduler: $scheduler)->list('tasks'));
        $details['forms'] = first_attributes(machine_api()->list('forms'));
        $drawn = [];
        $guided = guided_machine();
        $details['destinations'] = first_attributes(
            (new Api($guided->environment))->list('destinations', [], grid: 'demo'),
        );
        $details['fonts'] = first_attributes(
            (new Api($guided->environment, texts: text_renderer($drawn)))->list('fonts'),
        );

        expect(array_keys($details))->toEqualCanonicalizing(array_keys(Contract::RESOURCES));
        foreach ($details as $type => $detail) {
            unset($detail['id']);
            $known = array_keys(Contract::attributes($type));
            expect(array_values(array_diff(array_keys($detail), $known)))
                ->toBe([], "$type: given by the model, unknown to the contract")
                ->and(array_values(array_diff($known, array_keys($detail))))
                ->toBe([], "$type: in the contract, not given by the model");
        }
    });

    test('says the types of the values of the models', function () {
        $grid = Grid::find((new Machine())->environment, 'demo');
        $service = $grid->service('asset');
        Connections::using(static fn() => avatar_creation_database());
        $avatar = first_attributes(machine_api(avatar_grid())->list('avatars', [], grid: 'demo'));
        Connections::using(static fn() => region_database());
        $region = first_attributes(machine_api(region_config())->list('regions', [], grid: 'demo'));
        parcels_in(parcel_databases());
        $parcel = first_attributes(machine_api(parcel_config())->list('parcels', [], grid: 'demo'));
        $estate = first_attributes(machine_api(estate_world())->list('estates', [], grid: 'demo'));
        Connections::using(static fn() => asset_database());
        $asset = first_attributes(machine_api(asset_config())->list('assets', [], grid: 'demo'));
        $types = [
            'string' => 'is_string',
            'integer' => 'is_int',
            'boolean' => 'is_bool',
            'array' => 'is_array',
            'object' => 'is_array',
            'number' => 'is_numeric',
        ];

        $wrong = [];
        foreach (
            [
                'grids' => $grid->detail(),
                'services' => $service->detail(),
                'avatars' => $avatar,
                'regions' => $region,
                'parcels' => $parcel,
                'estates' => $estate,
            ]
            as $type => $detail
        ) {
            foreach (Contract::attributes($type) as $name => [$kind]) {
                $check = $types[rtrim($kind, '?')];
                $check($detail[$name]) ||
                    (str_ends_with($kind, '?') && $detail[$name] === null) ||
                    ($wrong[] = "$type.$name is $kind, the model gives " . get_debug_type($detail[$name]));
            }
        }
        expect($wrong)->toBe([]);
    });
});

describe('The API, for the assets of a grid', function () {
    test(
        'lists their records, and gives a texture as a picture, the picture of nothing with a 404 for what is not there',
        function () {
            Connections::using(static fn() => asset_database());
            $machine = new Machine();
            $id = sprintf(
                '%08x-0000-4000-8000-%012x',
                random_int(1, PHP_INT_MAX >> 40),
                random_int(1, PHP_INT_MAX >> 20),
            );
            $machine->http['http://localhost:8003/assets/' . $id] =
                '<AssetBase><Data>' . base64_encode('JP2-BYTES') . "</Data><ID>$id</ID><Type>0</Type></AssetBase>";
            $api = new Api($machine->environment, null, static fn(Grid $grid): GridConfig => asset_config());
            $calls = [];

            $list = $api->list('assets', ['filter' => ['type' => 'texture']], grid: 'demo');
            $picture = $api->media('demo', $id, 'png', asset_converter($calls));
            $nothing = $api->media('demo', 'ffffffff-0000-4000-8000-000000000000', 'png', asset_converter($calls));

            expect($list->document['meta']['total'])
                ->toBe(1)
                ->and($list->document['data'][0]['type'])
                ->toBe('assets')
                ->and($picture->status)
                ->toBe(200)
                ->and($picture->headers['Content-Type'])
                ->toBe('image/png')
                ->and($picture->body())
                ->toBe('png:JP2-BYTES')
                ->and($nothing->status)
                ->toBe(404)
                ->and($nothing->headers['Content-Type'])
                ->toBe('image/png')
                ->and($api->media('nowhere', $id)->status)
                ->toBe(404);
        },
    );
});

describe('The documentation of the API', function () {
    test(
        'is made from the contract by `composer docs`: the pages of docs/api are the ones of the contract',
        function () {
            $committed = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/docs/api/openapi.json'), true);
            $current = Contract::document();
            unset($committed['info']['version'], $current['info']['version']);

            expect($committed)->toBe($current);
        },
    )->skip(fn() => !is_file(dirname(__DIR__, 2) . '/docs/api/openapi.json'), 'the documentation is not made');

    test('is written by the script, with the document in the page', function () {
        $dir = test_tmp() . '/api-docs-' . bin2hex(random_bytes(4));
        exec(
            escapeshellarg(PHP_BINARY) .
                ' ' .
                escapeshellarg(dirname(__DIR__, 2) . '/dev/api-docs.php') .
                ' ' .
                escapeshellarg($dir) .
                ' 2>&1',
            $output,
            $code,
        );

        expect($code)
            ->toBe(0, implode("\n", $output))
            ->and("$dir/openapi.json")
            ->toBeFile()
            ->and("$dir/openapi.yaml")
            ->toBeFile()
            ->and(file_get_contents("$dir/index.html"))
            ->toContain('Redoc.init')
            ->toContain('"openapi": "3.1.0"')
            ->toContain('href="try.html"')
            ->and(file_get_contents("$dir/try.html"))
            ->toContain('SwaggerUIBundle')
            ->toContain('persistAuthorization')
            ->toContain('"ApiKey"');
    })->skip(fn() => !is_file(dirname(__DIR__, 2) . '/dev/api-docs.php'), 'the script is not there');
});

describe('The endpoints of a fixed format', function () {
    test('describe the methods the services answer, no more and no fewer', function () {
        $db = test_pdo();
        $money = new OpenSim\Economy\Currency(
            new OpenSim\Economy\CurrencySettings(scriptKey: 'key'),
            static fn() => null,
        );
        $server = new OpenSim\Services\CurrencyServer($money, '');

        expect(array_keys((new OpenSim\Services\SearchQuery($db, 'regionsregister'))->methods()))
            ->toBe(array_keys(OpenSim\Api\Formats::SEARCH))
            ->and(array_keys($server->currencyMethods()))
            ->toBe(array_keys(OpenSim\Api\Formats::CURRENCY))
            ->and(array_keys($server->landMethods()))
            ->toBe(array_keys(OpenSim\Api\Formats::LAND))
            ->and(array_keys(OpenSim\Api\Formats::OFFLINE))
            ->toBe(['SaveMessage', 'RetrieveMessages']);
    })->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

    test('are in the contract with the methods and examples of their calls', function () {
        $paths = Contract::document()['paths'];
        $query = $paths['/grids/{grid}/search/query']['post'];
        $currency = $paths['/grids/{grid}/currency.php']['post'];

        expect(array_keys($query['requestBody']['content']['text/xml']['examples']))
            ->toBe(array_keys(OpenSim\Api\Formats::SEARCH))
            ->and($query['description'])
            ->toContain('### `dir_places_query`', '| `text` | string |')
            ->and($query['requestBody']['content']['text/xml']['examples']['dir_places_query']['value'])
            ->toContain('<methodName>dir_places_query</methodName>', '<name>text</name>')
            ->and($query['responses']['200']['content']['text/xml']['examples']['dir_places_query']['value'])
            ->toContain('<name>parcel_id</name>')
            ->and(array_column($query['parameters'], 'name'))
            ->toBe(['grid', 'gk'])
            ->and(array_keys($currency['requestBody']['content']['text/xml']['examples']))
            ->toBe([
                'getCurrencyQuote',
                'buyCurrency',
                'simulatorUserBalanceRequest',
                'regionMoveMoney',
                'simulatorClaimUserRequest',
            ])
            ->and(array_column($paths['/grids/{grid}/search/register']['get']['parameters'], 'name'))
            ->toBe(['grid', 'host', 'port', 'service'])
            ->and($paths['/grids/{grid}/destinations']['get']['parameters'][1]['name'] ?? null)
            ->toBe('format')
            ->and(array_keys($paths['/grids/{grid}/destinations']['get']['responses']['200']['content']))
            ->toContain('text/plain');
    });

    test('are all in the contract, whatever the handler serves besides the resources', function () {
        $paths = Contract::document()['paths'];
        $served = [
            ['get', '/openapi.json', false],
            ['post', '/tasks/{id}/runs', true],
            ['get', '/grids/{grid}/assets/{id}.{format}', true],
            ['get', '/text-images', true],
            ['get', '/grids/{grid}/motd', true],
            ['get', '/grids/{grid}/search/register', true],
            ['post', '/grids/{grid}/search/query', true],
            ['post', '/grids/{grid}/offline-messages/SaveMessage', true],
            ['post', '/grids/{grid}/offline-messages/RetrieveMessages', true],
            ['post', '/grids/{grid}/currency.php', true],
            ['post', '/grids/{grid}/landtool.php', true],
        ];
        foreach ($served as [$method, $path, $documented]) {
            expect(isset($paths[$path][$method]))->toBe($documented, "$method $path");
        }
        // The services of the catalog whose address is a page or a feed have a page of their own in the contract
        expect(array_keys($paths))->toContain(
            '/search',
            '/welcome',
            '/guide',
            '/profile/{name}',
            '/grid_status',
            '/grid_status.rss',
        );
    });
});
