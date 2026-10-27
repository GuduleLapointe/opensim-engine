<?php

declare(strict_types=1);

/**
 * The API is closed: what manages the machine and the grids needs a key, made and revoked on the machine; what the viewers, the
 * simulators and the visitors call is open. The contract says which is which, and the router does as the contract says.
 */

use Nyholm\Psr7\Factory\Psr17Factory;
use OpenSim\Api\Api;
use OpenSim\Api\Contract;
use OpenSim\Api\Handler;
use OpenSim\Api\Router;
use OpenSim\Security\ApiKeys;
use OpenSim\Testing\ClockFake;
use OpenSim\Testing\Machine;

/** The keys of a test install. */
function auth_keys(?ClockFake $clock = null): ApiKeys
{
    return new ApiKeys(test_tmp() . '/keys-' . bin2hex(random_bytes(4)) . '/api-keys.json', $clock);
}

/** A handler of the test machine, closed unless it is given keys. */
function auth_handler(?ApiKeys $keys = null): Handler
{
    $factory = new Psr17Factory();

    return new Handler(new Api((new Machine())->environment), $factory, $factory, '/api', authenticator: $keys);
}

/** The answer to a request, with a key when there is one. */
function auth_ask(
    Handler $handler,
    string $method,
    string $path,
    ?string $key = null,
): OpenSim\Api\Response|Psr\Http\Message\ResponseInterface {
    $factory = new Psr17Factory();
    $request = $factory->createServerRequest($method, $path);
    $key !== null && ($request = $request->withHeader('Authorization', "Bearer $key"));

    return $handler->handle($request);
}

describe('The keys of the API', function () {
    test('are made once, kept as what cannot open anything, and listed without it', function () {
        $clock = new ClockFake();
        $keys = auth_keys($clock);

        ['id' => $id, 'key' => $key] = $keys->create('manager');
        $file = (string) file_get_contents((new ReflectionProperty($keys, 'file'))->getValue($keys));

        expect($key)
            ->toStartWith('osk_')
            ->toHaveLength(47)
            ->and($id)
            ->toHaveLength(8)
            ->and($file)
            ->not->toContain($key)
            ->toContain(hash('sha256', $key))
            ->and($keys->all())
            ->toBe([['id' => $id, 'label' => 'manager', 'created' => 1000, 'revoked' => null]])
            ->and(substr(sprintf('%o', fileperms((new ReflectionProperty($keys, 'file'))->getValue($keys))), -3))
            ->toBe('640');
    });

    test('open the API as long as they are not revoked, by their id or their label', function () {
        $keys = auth_keys();
        ['id' => $first, 'key' => $one] = $keys->create('manager');
        ['key' => $two] = $keys->create('plugin');

        expect($keys->allows($one))
            ->toBeTrue()
            ->and($keys->allows($two))
            ->toBeTrue()
            ->and($keys->allows('osk_forged'))
            ->toBeFalse()
            ->and($keys->allows(substr($one, 4)))
            ->toBeFalse()
            ->and($keys->allows(null))
            ->toBeFalse()
            ->and($keys->revoke($first))
            ->toBeTrue()
            ->and($keys->allows($one))
            ->toBeFalse()
            ->and($keys->revoke('plugin'))
            ->toBeTrue()
            ->and($keys->allows($two))
            ->toBeFalse()
            ->and($keys->revoke('nobody'))
            ->toBeFalse()
            ->and(array_column($keys->all(), 'revoked'))
            ->each->not->toBeNull();
    });

    test('are read from the header Authorization, Bearer', function () {
        expect(ApiKeys::token('Bearer osk_abc'))
            ->toBe('osk_abc')
            ->and(ApiKeys::token('bearer   osk_abc '))
            ->toBe('osk_abc')
            ->and(ApiKeys::token('Basic dXNlcg=='))
            ->toBeNull()
            ->and(ApiKeys::token(''))
            ->toBeNull();
    });
});

describe('The API handler, closed', function () {
    test('answers 401 to what is not public, until it is given the keys of the install', function () {
        $closed = auth_handler();

        $grids = auth_ask($closed, 'GET', '/api/grids');
        $health = auth_ask($closed, 'GET', '/api/grids/demo/health');
        $layout = auth_ask($closed, 'GET', '/api/layout');
        $run = auth_ask($closed, 'POST', '/api/tasks/search-parse/runs');

        expect($grids->getStatusCode())
            ->toBe(401)
            ->and($grids->getHeaderLine('WWW-Authenticate'))
            ->toBe('Bearer realm="opensim"')
            ->and($grids->getHeaderLine('Content-Type'))
            ->toBe('application/problem+json')
            ->and(json_decode((string) $grids->getBody(), true)['detail'])
            ->toContain('API key')
            ->and([$health->getStatusCode(), $run->getStatusCode()])
            ->toBe([401, 401])
            ->and($layout->getStatusCode())
            ->toBe(401);
    });

    test('lets in a key that is valid, and refuses a forged one, a revoked one, and no key', function () {
        $keys = auth_keys();
        ['id' => $id, 'key' => $key] = $keys->create('manager');
        $handler = auth_handler($keys);

        $good = auth_ask($handler, 'GET', '/api/grids', $key);
        $forged = auth_ask($handler, 'GET', '/api/grids', 'osk_forged');
        $none = auth_ask($handler, 'GET', '/api/grids');
        $keys->revoke($id);
        $revoked = auth_ask($handler, 'GET', '/api/grids', $key);

        expect([
            $good->getStatusCode(),
            $forged->getStatusCode(),
            $none->getStatusCode(),
            $revoked->getStatusCode(),
        ])->toBe([200, 401, 401, 401]);
    });

    test('lets everyone in to what the viewers, the simulators and the visitors call', function () {
        $closed = auth_handler();

        $codes = [
            auth_ask($closed, 'GET', '/api/openapi.json')->getStatusCode(),
            auth_ask($closed, 'GET', '/api/grids/demo/motd')->getStatusCode(),
            auth_ask($closed, 'GET', '/api/grids/demo/destinations')->getStatusCode(),
            auth_ask($closed, 'GET', '/api/grids/demo/monitor?format=text')->getStatusCode(),
            auth_ask($closed, 'GET', '/api/grids/demo/addresses')->getStatusCode(),
            auth_ask($closed, 'GET', '/api/fonts')->getStatusCode(),
        ];

        expect($codes)->each->toBe(200);
    });
});

describe('The contract and the router, on what is open', function () {
    test(
        'agree: every operation the contract says is open is public for the router, and the others need a key',
        function () {
            $document = Contract::document();
            $closed = auth_handler();
            $served = 0;
            foreach ($document['paths'] as $path => $operations) {
                foreach ($operations as $method => $operation) {
                    // The pages and what Robust serves are not served by the router
                    if (
                        in_array($operation['tags'][0] ?? '', ['Pages', 'Grid info'], true) ||
                        $path === '/openapi.json'
                    ) {
                        continue;
                    }
                    $open = ($operation['security'] ?? null) === [];
                    $concrete = strtr($path, ['{grid}' => 'demo', '{id}' => 'x', '{name}' => 'x', '{format}' => 'png']);
                    $concrete = str_replace('x.png', 'x.png', $concrete);
                    $status = auth_ask($closed, strtoupper($method), '/api' . $concrete)->getStatusCode();
                    expect($status === 401)->toBe(
                        !$open,
                        strtoupper($method) .
                            " $path is " .
                            ($open ? 'open' : 'closed') .
                            " in the contract, the router answers $status",
                    );
                    $served++;
                }
            }

            expect($served)
                ->toBeGreaterThan(40)
                ->and($document['security'])
                ->toBe([['ApiKey' => []]]);
        },
    );

    test('say how to open the API: a bearer key, and the public list of the router is the contract\'s', function () {
        $document = Contract::document();

        expect($document['components']['securitySchemes']['ApiKey'])
            ->toMatchArray(['type' => 'http', 'scheme' => 'bearer'])
            ->and($document['info']['description'])
            ->toContain('opensim api-key create', 'try.html');
        foreach (Router::PUBLIC as $public) {
            [$method, $path] = explode(' ', $public, 2);
            $path === '/openapi.json' ||
                expect($document['paths'][$path][strtolower($method)]['security'] ?? null)->toBe([], $public);
        }
    });
});
