<?php

declare(strict_types=1);

/**
 * The API as a PSR-15 handler: the addresses of the contract, the methods each takes, the body of a change, the answers as PSR-7
 * messages of whatever implementation gives the factories.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenSim\Api\Api;
use OpenSim\Api\Handler;
use OpenSim\Assets\TextRendererInterface;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Models\Grid;
use OpenSim\Scheduling\Scheduler;
use OpenSim\Scheduling\Task;
use OpenSim\Testing\ClockFake;
use OpenSim\Testing\Machine;
use Psr\Http\Message\ResponseInterface;

/** A handler over the test machine, mounted under `/api`. */
function api_handler(
    ?Scheduler $scheduler = null,
    ?Machine $machine = null,
    ?GridConfig $config = null,
    ?TextRendererInterface $texts = null,
    ?string $grid = null,
): Handler {
    $factory = new Psr17Factory();
    $machine ??= new Machine();
    $calls = [];

    return new Handler(
        new Api(
            $machine->environment,
            $scheduler,
            $config === null ? null : static fn(Grid $found): GridConfig => $config,
            $texts,
        ),
        $factory,
        $factory,
        '/api',
        asset_converter($calls),
        $grid,
    );
}

/** What the handler answers to a request; a document is sent as the JSON:API body it is. */
function api_ask(
    Handler $handler,
    string $method,
    string $address,
    ?array $document = null,
    ?string $raw = null,
): ResponseInterface {
    $factory = new Psr17Factory();
    $request = $factory->createServerRequest($method, $address);
    parse_str((string) parse_url($address, PHP_URL_QUERY), $query);
    $request = $request->withQueryParams($query);
    $body = $raw ?? ($document === null ? '' : json_encode($document));

    return $handler->handle($request->withBody($factory->createStream($body)));
}

afterEach(fn() => Connections::using(null));

describe('The API handler', function () {
    test('lists and reads the resources under the mount point, as JSON:API', function () {
        $handler = api_handler();

        $list = api_ask($handler, 'GET', '/api/services?filter[served]=yes&page[size]=1&sort=-name');
        $one = api_ask($handler, 'GET', '/api/simulators/alpha');
        $grid = api_ask($handler, 'GET', '/api/grids/demo');

        expect($list->getStatusCode())
            ->toBe(200)
            ->and($list->getHeaderLine('Content-Type'))
            ->toBe('application/vnd.api+json')
            ->and(json_decode((string) $list->getBody(), true)['meta'])
            ->toMatchArray(['total' => 2, 'perPage' => 1, 'sort' => 'name desc'])
            ->and(json_decode((string) $one->getBody(), true)['data'])
            ->toMatchArray(['type' => 'simulators'])
            ->and(json_decode((string) $one->getBody(), true)['data']['id'])
            ->toEndWith(':9000')
            ->and(json_decode((string) $grid->getBody(), true)['data']['id'])
            ->toBe('demo');
    });

    test('answers the problems of the API as problems, with their status', function () {
        $handler = api_handler();

        $missing = api_ask($handler, 'GET', '/api/simulators/nobody');
        $outside = api_ask($handler, 'GET', '/other/simulators');
        $nowhere = api_ask($handler, 'GET', '/api');
        $unknown = api_ask($handler, 'GET', '/api/nonsense');

        expect($missing->getStatusCode())
            ->toBe(404)
            ->and($missing->getHeaderLine('Content-Type'))
            ->toBe('application/problem+json')
            ->and(json_decode((string) $missing->getBody(), true))
            ->toMatchArray(['status' => 404, 'instance' => '/simulators/nobody'])
            ->and([$outside->getStatusCode(), $nowhere->getStatusCode(), $unknown->getStatusCode()])
            ->toBe([404, 404, 404]);
    });

    test('says which methods an address takes when it is asked with another', function () {
        $handler = api_handler();

        $collection = api_ask($handler, 'DELETE', '/api/services');
        $item = api_ask($handler, 'POST', '/api/simulators/alpha');
        $openapi = api_ask($handler, 'PATCH', '/api/openapi.json');

        expect($collection->getStatusCode())
            ->toBe(405)
            ->and($collection->getHeaderLine('Allow'))
            ->toBe('GET, POST')
            ->and($item->getHeaderLine('Allow'))
            ->toBe('GET, PATCH, DELETE')
            ->and($openapi->getHeaderLine('Allow'))
            ->toBe('GET')
            ->and(json_decode((string) $collection->getBody(), true)['status'])
            ->toBe(405);
    });

    test('changes a resource with the attributes of the JSON:API document of its body', function () {
        $handler = api_handler();
        $document = static fn(string $type, array $attributes): array => [
            'data' => ['type' => $type, 'attributes' => $attributes],
        ];

        $off = api_ask($handler, 'PATCH', '/api/simulators/alpha', $document('simulators', ['enabled' => false]));
        $made = api_ask($handler, 'POST', '/api/grids', $document('grids', ['name' => 'New']));

        expect($off->getStatusCode())
            ->toBe(200)
            ->and(json_decode((string) $off->getBody(), true)['data']['attributes']['enabled'])
            ->toBeFalse()
            ->and($made->getStatusCode())
            ->toBe(503)
            ->and(api_ask($handler, 'GET', '/api/simulators/alpha')->getStatusCode())
            ->toBe(200);
    });

    test('refuses a body that is not a document of the type, with a problem that says why', function () {
        $handler = api_handler();

        $notJson = api_ask($handler, 'PATCH', '/api/simulators/alpha', raw: '{nope');
        $noData = api_ask($handler, 'PATCH', '/api/simulators/alpha', ['enabled' => false]);
        $otherType = api_ask($handler, 'PATCH', '/api/simulators/alpha', ['data' => ['type' => 'grids']]);

        expect($notJson->getStatusCode())
            ->toBe(400)
            ->and($noData->getStatusCode())
            ->toBe(400)
            ->and(json_decode((string) $noData->getBody(), true)['detail'])
            ->toContain("no 'data'")
            ->and($otherType->getStatusCode())
            ->toBe(409)
            ->and(api_ask($handler, 'GET', '/api/simulators/alpha')->getStatusCode())
            ->toBe(200);
    });

    test('runs a task now, and lists them', function () {
        $scheduler = new Scheduler(test_tmp() . '/handler-cron-' . bin2hex(random_bytes(4)), new ClockFake());
        $scheduler->add(new Task('count', 3600, fn() => 'counted', 'Count'));
        $handler = api_handler($scheduler);

        $run = api_ask($handler, 'POST', '/api/tasks/count/runs');
        $list = api_ask($handler, 'GET', '/api/tasks');

        expect($run->getStatusCode())
            ->toBe(200)
            ->and(json_decode((string) $run->getBody(), true)['data']['attributes'])
            ->toMatchArray(['task' => 'count', 'message' => 'counted'])
            ->and(json_decode((string) $list->getBody(), true)['data'][0]['id'])
            ->toBe('count')
            ->and(api_ask($handler, 'POST', '/api/tasks/nothing/runs')->getStatusCode())
            ->toBe(404);
    });

    test('gives its own contract at /openapi.json', function () {
        $answer = api_ask(api_handler(), 'GET', '/api/openapi.json');
        $contract = json_decode((string) $answer->getBody(), true);

        expect($answer->getStatusCode())
            ->toBe(200)
            ->and($answer->getHeaderLine('Content-Type'))
            ->toBe('application/vnd.oai.openapi+json')
            ->and($contract['paths'])
            ->toHaveKey('/grids/{grid}/assets/{id}.{format}');
    });

    test(
        'gives a picture of a grid as it is, resized when it is asked, and the picture of nothing for the rest',
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
            $handler = api_handler(machine: $machine, config: asset_config());

            $picture = api_ask($handler, 'GET', "/api/grids/demo/assets/$id.png");
            $nothing = api_ask($handler, 'GET', '/api/grids/demo/assets/ffffffff-0000-4000-8000-000000000000.png');

            expect($picture->getStatusCode())
                ->toBe(200)
                ->and($picture->getHeaderLine('Content-Type'))
                ->toBe('image/png')
                ->and((string) $picture->getBody())
                ->toBe('png:JP2-BYTES')
                ->and($picture->getHeaderLine('Content-Length'))
                ->toBe((string) strlen('png:JP2-BYTES'))
                ->and($nothing->getStatusCode())
                ->toBe(404)
                ->and($nothing->getHeaderLine('Content-Type'))
                ->toBe('image/png');
        },
    );
});

/** A renderer that makes a text of the picture, and has two fonts. */
function text_renderer(array &$drawn): TextRendererInterface
{
    return new class ($drawn) implements TextRendererInterface {
        public function __construct(private array &$drawn) {}

        public function render(string $text, string $font, int $size, string $color, string $background): ?string
        {
            if ($color === 'nocolor') {
                throw new InvalidArgumentException("the text cannot be drawn with the color '$color'");
            }
            $this->drawn[] = [$text, $font, $size, $color, $background];

            return $text === '' ? null : "png:$text";
        }

        public function fonts(): array
        {
            return ['Impact', 'DejaVu-Sans'];
        }
    };
}

/** The test machine with the files of a guide and a message of the day in the folder of its grid. */
function guided_machine(): Machine
{
    $machine = new Machine();
    $machine->write(
        $machine->grid . '/Helpers.ini',
        "[Helpers]\nGuideSource = \"destinations.txt\"\n[LoginService]\nWelcomeMessage = \"Hello <USERNAME>, welcome\"\n",
    );
    $machine->write(
        $machine->grid . '/destinations.txt',
        "Welcome|grid.example.org:8002/Welcome\nGardens\nRose garden|grid.example.org:8002/Roses|128,64,30\nWelcome|grid.example.org:8002/Another\n",
    );

    return $machine;
}

describe('The API handler, for the pages of a grid', function () {
    test('gives the message of the day as text, with the name of the avatar when it is given', function () {
        $handler = api_handler(machine: guided_machine());

        $text = api_ask($handler, 'GET', '/api/grids/demo/motd');
        $named = api_ask($handler, 'GET', '/api/grids/demo/motd?username=Ada%20Love');

        expect($text->getStatusCode())
            ->toBe(200)
            ->and($text->getHeaderLine('Content-Type'))
            ->toBe('text/plain; charset=utf-8')
            ->and((string) $text->getBody())
            ->toBe('Hello <USERNAME>, welcome')
            ->and((string) $named->getBody())
            ->toBe('Hello Ada Love, welcome')
            ->and(api_ask($handler, 'GET', '/api/grids/nowhere/motd')->getStatusCode())
            ->toBe(404)
            ->and((string) api_ask(api_handler(), 'GET', '/api/grids/demo/motd?username=Ada')->getBody())
            ->toBe('Welcome to Demo, Ada!');
    });

    test('lists the destinations of the guide as resources, by category, each with an id of its own', function () {
        $handler = api_handler(machine: guided_machine());

        $list = json_decode((string) api_ask($handler, 'GET', '/api/grids/demo/destinations')->getBody(), true);
        $one = api_ask($handler, 'GET', '/api/grids/demo/destinations/gardens-rose-garden');

        expect(array_column($list['data'], 'id'))
            ->toBe(['destinations-guide-welcome', 'gardens-rose-garden', 'gardens-welcome'])
            ->and($list['data'][1]['attributes'])
            ->toBe(['name' => 'Rose garden', 'category' => 'Gardens', 'url' => 'grid.example.org:8002/Roses/128/64/30'])
            ->and($list['meta']['categories'])
            ->toBe(['Destinations Guide', 'Gardens'])
            ->and($list['data'][1]['links']['self'])
            ->toBe('/grids/demo/destinations/gardens-rose-garden')
            ->and($one->getStatusCode())
            ->toBe(200)
            ->and(api_ask($handler, 'GET', '/api/grids/demo/destinations/nothing')->getStatusCode())
            ->toBe(404)
            ->and(api_ask($handler, 'GET', '/api/grids/nowhere/destinations')->getStatusCode())
            ->toBe(404)
            ->and(
                json_decode((string) api_ask(api_handler(), 'GET', '/api/grids/demo/destinations')->getBody(), true)[
                    'data'
                ],
            )
            ->toBe([]);
    });

    test('answers without the grid on a host that serves one', function () {
        $handler = api_handler(machine: guided_machine(), grid: 'demo');

        expect((string) api_ask($handler, 'GET', '/api/motd?username=Ada')->getBody())
            ->toBe('Hello Ada, welcome')
            ->and(json_decode((string) api_ask($handler, 'GET', '/api/destinations')->getBody(), true)['meta']['total'])
            ->toBe(3)
            ->and(api_ask($handler, 'GET', '/api/destinations/gardens-welcome')->getStatusCode())
            ->toBe(200)
            ->and(api_ask(api_handler(machine: guided_machine()), 'GET', '/api/motd')->getStatusCode())
            ->toBe(404);
    });
});

describe('The API handler, for the images of a text', function () {
    test('draws a text as a picture, with the font, the size and the colors asked, and the limits', function () {
        $drawn = [];
        $handler = api_handler(texts: text_renderer($drawn));

        $plain = api_ask($handler, 'GET', '/api/text-images?text=Hello%20World');
        $styled = api_ask(
            $handler,
            'GET',
            '/api/text-images?text=Hi&font=DejaVu-Sans&size=5000&color=%23ff0000&background=blue&download=true',
        );
        $default = api_ask($handler, 'GET', '/api/text-images?size=-3');
        $long = api_ask($handler, 'GET', '/api/text-images?text=' . str_repeat('x', 600));

        expect($plain->getStatusCode())
            ->toBe(200)
            ->and($plain->getHeaderLine('Content-Type'))
            ->toBe('image/png')
            ->and((string) $plain->getBody())
            ->toBe('png:Hello World')
            ->and($plain->hasHeader('Content-Disposition'))
            ->toBeFalse()
            ->and($styled->getHeaderLine('Content-Disposition'))
            ->toBe('attachment; filename="Hi.png"')
            ->and($drawn[0])
            ->toBe(['Hello World', 'Impact', 120, 'white', 'black'])
            ->and($drawn[1])
            ->toBe(['Hi', 'DejaVu-Sans', 1000, '#ff0000', 'blue'])
            ->and((string) $default->getBody())
            ->toBe('png:Impact')
            ->and($drawn[2][2])
            ->toBe(120)
            ->and(strlen((string) $long->getBody()))
            ->toBe(strlen('png:') + 500);
    });

    test(
        'says why it cannot: a color that is not one is a bad request, a machine that cannot draw is not available',
        function () {
            $drawn = [];
            $handler = api_handler(texts: text_renderer($drawn));

            $bad = api_ask($handler, 'GET', '/api/text-images?text=x&color=nocolor');
            $blind = api_ask($handler, 'GET', '/api/text-images?text=');

            expect($bad->getStatusCode())
                ->toBe(400)
                ->and(json_decode((string) $bad->getBody(), true)['detail'])
                ->toContain('nocolor')
                ->and($blind->getStatusCode())
                ->toBe(503)
                ->and(api_ask($handler, 'POST', '/api/text-images')->getHeaderLine('Allow'))
                ->toBe('GET');
        },
    );

    test('lists the fonts the machine has, each one by its name', function () {
        $drawn = [];
        $handler = api_handler(texts: text_renderer($drawn));

        $list = json_decode((string) api_ask($handler, 'GET', '/api/fonts')->getBody(), true);

        expect(array_column($list['data'], 'id'))
            ->toBe(['Impact', 'DejaVu-Sans'])
            ->and($list['data'][0])
            ->toMatchArray(['type' => 'fonts', 'attributes' => ['name' => 'Impact']])
            ->and(api_ask($handler, 'GET', '/api/fonts/Impact')->getStatusCode())
            ->toBe(200)
            ->and(api_ask($handler, 'GET', '/api/fonts/Comic')->getStatusCode())
            ->toBe(404);
    });
});

describe('The API handler, for the search', function () {
    test('registers a simulator, and reads its snapshot once the answer is sent', function () {
        $db = search_database();
        Connections::using(static fn($what) => $what === OpenSim\Data\Database::Search ? $db : null);
        $machine = new Machine();
        $machine->http['http://sim.example.org:9000/?method=collector'] = search_snapshot();
        $handler = api_handler(machine: $machine);

        $answer = api_ask(
            $handler,
            'GET',
            '/api/grids/demo/search/register?host=sim.example.org&port=9000&service=online',
        );
        $registered = $db->get_var('SELECT COUNT(*) FROM hostsregister');
        $before = $db->get_var('SELECT COUNT(*) FROM regionsregister');
        $handler->terminate();

        expect($answer->getStatusCode())
            ->toBe(200)
            ->and((string) $answer->getBody())
            ->toBe('')
            ->and([$registered, $before])
            ->toBe([1, 0])
            ->and($db->get_var('SELECT COUNT(*) FROM regionsregister'))
            ->toBe(1)
            ->and($machine->clock->slept)
            ->toBe([30.0]);

        $gone = api_ask(
            $handler,
            'GET',
            '/api/grids/demo/search/register?host=sim.example.org&port=9000&service=offline',
        );

        expect($gone->getStatusCode())
            ->toBe(200)
            ->and($db->get_var('SELECT COUNT(*) FROM hostsregister'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM parcels'))
            ->toBe(0);
    });

    test(
        'refuses a registration that does not say which simulator, which service, or that has no search database',
        function () {
            $db = search_database();
            Connections::using(static fn($what) => $what === OpenSim\Data\Database::Search ? $db : null);
            $handler = api_handler();

            $noPort = api_ask($handler, 'GET', '/api/grids/demo/search/register?host=sim.example.org&service=online');
            $noService = api_ask(
                $handler,
                'GET',
                '/api/grids/demo/search/register?host=sim.example.org&port=9000&service=maybe',
            );
            $post = api_ask($handler, 'POST', '/api/grids/demo/search/register');
            Connections::using(static fn() => null);
            $noDatabase = api_ask(
                $handler,
                'GET',
                '/api/grids/demo/search/register?host=sim.example.org&port=9000&service=online',
            );

            expect($noPort->getStatusCode())
                ->toBe(400)
                ->and((string) $noPort->getBody())
                ->toContain('missing simulator host and/or port')
                ->and($noService->getStatusCode())
                ->toBe(400)
                ->and($post->getHeaderLine('Allow'))
                ->toBe('GET')
                ->and($noDatabase->getStatusCode())
                ->toBe(503)
                ->and((string) $noDatabase->getBody())
                ->toContain('search database is not available');
        },
    );

    test('answers the questions of the viewers in XML-RPC, for the grid the gk argument says', function () {
        [$db] = searched();
        Connections::using(static fn($what) => $what === OpenSim\Data\Database::Search ? $db : null);
        $handler = api_handler(grid: 'demo');
        $ask = static function (string $gk) use ($handler): ResponseInterface {
            $factory = new Psr17Factory();
            $request = $factory
                ->createServerRequest('POST', "/api/search/query?gk=$gk")
                ->withQueryParams(['gk' => $gk])
                ->withBody($factory->createStream(rpc_xml('dir_places_query', ['text' => 'rose'])));

            return $handler->handle($request);
        };

        $ours = $ask('grid.example.org:8002');
        $theirs = $ask('other.example.org:8002');

        expect($ours->getStatusCode())
            ->toBe(200)
            ->and($ours->getHeaderLine('Content-Type'))
            ->toBe('text/xml')
            ->and((string) $ours->getBody())
            ->toContain('Rose garden')
            ->and((string) $theirs->getBody())
            ->toContain('No results')
            ->and(api_ask($handler, 'GET', '/api/search/query')->getHeaderLine('Allow'))
            ->toBe('POST')
            ->and((string) api_ask($handler, 'POST', '/api/search/query', raw: 'not xml')->getBody())
            ->toContain('no XML-RPC request');
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The API handler, for the default guide', function () {
    test('gives the places the search knows as resources, or as the lines the boards read', function () {
        $db = guide_database();
        $machine = new Machine();
        $gatekeeper = OpenSim\Hypergrid\Destination::loginUrl(
            (string) Grid::find($machine->environment, 'demo')->uri(),
        );
        $db->exec(
            "UPDATE parcels SET gatekeeperURL = '$gatekeeper' WHERE gatekeeperURL = 'http://grid.example.org:8002'",
        );
        $db->exec(
            "UPDATE popularplaces SET gatekeeperURL = '$gatekeeper' WHERE gatekeeperURL = 'http://grid.example.org:8002'",
        );
        Connections::using(static fn($what) => $what === OpenSim\Data\Database::Search ? $db : null);
        $handler = api_handler(machine: $machine);

        $list = json_decode((string) api_ask($handler, 'GET', '/api/grids/demo/destinations')->getBody(), true);
        $lines = api_ask($handler, 'GET', '/api/grids/demo/destinations?format=list');

        expect(array_column(array_column($list['data'], 'attributes'), 'name'))
            ->toBe(['Rose garden', 'Market', 'Library'])
            ->and($list['meta']['categories'])
            ->toBe(['Popular places', 'Places in the search'])
            ->and($lines->getHeaderLine('Content-Type'))
            ->toBe('text/plain; charset=utf-8')
            ->and((string) $lines->getBody())
            ->toStartWith("Popular places\nRose garden|")
            ->toContain("\nPlaces in the search\nLibrary|");

        $factory = new Psr17Factory();
        $accepted = $handler->handle(
            $factory->createServerRequest('GET', '/api/grids/demo/destinations')->withHeader('Accept', 'text/plain'),
        );

        expect((string) $accepted->getBody())->toBe((string) $lines->getBody());
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');
