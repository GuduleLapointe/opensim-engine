<?php

declare(strict_types=1);

/**
 * The resources of the machine as JSON:API documents, the errors as problems, and the contract that says so: checked against the models.
 */

use OpenSim\Api\Api;
use OpenSim\Api\Contract;
use OpenSim\Api\Problem;
use OpenSim\Api\Response;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Grid;
use OpenSim\Testing\Machine;

describe('The API', function () {
    test('lists the resources of a type in a JSON:API document, with the page and what was applied', function () {
        $api = new Api((new Machine())->environment);
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
        $api = new Api((new Machine())->environment);

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
        $api = new Api((new Machine())->environment);

        $missing = $api->get('grids', 'nowhere');
        $kind = $api->list('nothings');

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
            ->toBe([]);
    });
});

describe('The contract', function () {
    test('is an OpenAPI 3.1 document with a list and a read for each resource, and the problem', function () {
        $document = Contract::document();

        expect($document['openapi'])
            ->toBe('3.1.0')
            ->and(array_keys($document['paths']))
            ->toBe(['/grids', '/grids/{id}', '/simulators', '/simulators/{id}', '/services', '/services/{id}'])
            ->and(array_keys($document['components']['schemas']))
            ->toBe(['Problem', 'Grids', 'Simulators', 'Services'])
            ->and($document['paths']['/services']['get']['parameters'])
            ->toContain([
                'name' => 'filter[served]',
                'in' => 'query',
                'schema' => ['type' => 'string', 'enum' => ['yes', 'no']],
            ])
            ->and($document['paths']['/grids/{id}']['get']['responses'])
            ->toHaveKeys(['200', '404'])
            ->and(json_encode($document))
            ->not->toBeFalse();
    });

    test('knows every attribute the models give, and only them', function () {
        $machine = new Machine();
        $grid = Grid::find($machine->environment, 'demo');
        $details = [
            'grids' => $grid->detail(),
            'simulators' => $grid->simulator('alpha')->detail(),
            'services' => $grid->service('asset')->detail(),
        ];

        foreach ($details as $type => $detail) {
            unset($detail['id']);
            $known = array_keys(Contract::ATTRIBUTES[$type]);
            expect(array_values(array_diff(array_keys($detail), $known)))
                ->toBe([], "$type: given by the model, unknown to the contract")
                ->and(array_values(array_diff($known, array_keys($detail))))
                ->toBe([], "$type: in the contract, not given by the model");
        }
    });

    test('says the types of the values of the models', function () {
        $machine = new Machine();
        $detail = Grid::find($machine->environment, 'demo')->detail();
        $types = [
            'string' => 'is_string',
            'integer' => 'is_int',
            'boolean' => 'is_bool',
            'array' => 'is_array',
            'object' => 'is_array',
        ];

        foreach (Contract::ATTRIBUTES['grids'] as $name => $kind) {
            $nullable = str_ends_with($kind, '?');
            $check = $types[rtrim($kind, '?')];
            expect($check($detail[$name]) || ($nullable && $detail[$name] === null))->toBeTrue(
                "grids.$name is {$kind}",
            );
        }
    });
});
