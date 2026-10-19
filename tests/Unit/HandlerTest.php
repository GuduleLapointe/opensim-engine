<?php

declare(strict_types=1);

/**
 * The API as a PSR-15 handler: the addresses of the contract, the methods each takes, the body of a change, the answers as PSR-7
 * messages of whatever implementation gives the factories.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenSim\Api\Api;
use OpenSim\Api\Handler;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Models\Grid;
use OpenSim\Scheduling\Scheduler;
use OpenSim\Scheduling\Task;
use OpenSim\Testing\ClockFake;
use OpenSim\Testing\Machine;
use Psr\Http\Message\ResponseInterface;

/** A handler over the test machine, mounted under `/api`. */
function api_handler(?Scheduler $scheduler = null, ?Machine $machine = null, ?GridConfig $config = null): Handler
{
    $factory = new Psr17Factory();
    $machine ??= new Machine();
    $calls = [];

    return new Handler(
        new Api(
            $machine->environment,
            $scheduler,
            $config === null ? null : static fn(Grid $grid): GridConfig => $config,
        ),
        $factory,
        $factory,
        '/api',
        asset_converter($calls),
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
