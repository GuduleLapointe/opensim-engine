<?php

declare(strict_types=1);

namespace OpenSim\Api;

use OpenSim\Assets\ConverterInterface;
use OpenSim\Models\Image;
use OpenSim\Security\ApiKeys;
use OpenSim\Security\Authenticator;

/**
 * The addresses of the API, without a transport: a method, a path, a query and a body in, an `Api\Response` out. The adapters are
 * thin: `Handler` (PSR-15, for a web host), `Cli` (the command line), and the PHP library, which calls the router the way a program
 * calls an API without HTTP.
 *
 *     $router = new Router(new Api($environment), grid: 'vonda');
 *     $router->get('/monitor')->document;                              // the state of the grid
 *     $router->post('/avatars', ['data' => ['type' => 'avatars', 'attributes' => ['name' => 'Ada Love']]]);
 *
 * The addresses are the ones of the contract (see Contract::document()): `/{type}` and `/{type}/{id}` for the resources of the
 * machine, `/grids/{grid}/{type}` and `/grids/{grid}/{type}/{id}` for the ones of a grid, `/grids/{grid}/assets/{id}.{format}` for a
 * picture, `/grids/{grid}/motd`, `/text-images`, `POST /tasks/{id}/runs`, and the contract itself at `/openapi.json`. A host that
 * serves one grid says so (`grid:`) and asks for its resources without it (`/motd`, `/destinations`, `/avatars`). The body of a
 * creation or a change is a JSON:API document, the errors are problems (RFC 9457).
 */
final class Router
{
    /** The content type of the contract. */
    public const OPENAPI = 'application/vnd.oai.openapi+json';

    /**
     * What needs no key: what the viewers, the simulators and the visitors of a site call (the message of the day, the guide, the
     * pictures, the status of the grid), and what OpenSimulator and the viewers call in a format of their own, which they protect with
     * their own means (the secure session of an avatar, the secret of a region, the key of the money server). Everything else is for
     * the operator and the applications that hold a key.
     */
    public const PUBLIC = [
        'GET /openapi.json',
        'GET /text-images',
        'GET /fonts',
        'GET /fonts/{id}',
        'GET /grids/{grid}/destinations',
        'GET /grids/{grid}/destinations/{id}',
        'GET /grids/{grid}/motd',
        'GET /grids/{grid}/addresses',
        'GET /grids/{grid}/info',
        'GET /grids/{grid}/monitor',
        'GET /grids/{grid}/assets/{id}.{format}',
        'GET /grids/{grid}/search/register',
        'POST /grids/{grid}/search/query',
        'POST /grids/{grid}/offline-messages/SaveMessage',
        'POST /grids/{grid}/offline-messages/RetrieveMessages',
        'POST /grids/{grid}/currency.php',
        'POST /grids/{grid}/landtool.php',
    ];

    /**
     * @param string $base where the application mounts the API (`/api`): the beginning of the paths it does not answer for
     * @param ?ConverterInterface $converter converts the pictures of the grid (default: the one of the machine)
     * @param ?string $grid the grid a web host serves: what is asked of a grid without saying which (`/motd`, `/destinations`,
     *        `/avatars`, `/assets/{id}.png`) is asked of this one
     */
    public function __construct(
        private readonly Api $api,
        private readonly string $base = '',
        private readonly ?ConverterInterface $converter = null,
        private readonly ?string $grid = null,
        private readonly ?Authenticator $authenticator = null,
    ) {}

    /**
     * The answer to a request.
     *
     * @param array<string,mixed> $query the arguments of the address
     * @param string $body the body as it was sent
     * @param string $ip the address of whoever calls (a quote of currency is tied to it)
     * @param string $accept the `Accept` header: the boards of the scripts ask the guide as plain text
     * @param ?array<string,mixed> $parsed the body when the transport already read it (a form)
     * @param string $authorization the `Authorization` header: `Bearer <key>`
     */
    public function route(
        string $method,
        string $path,
        array $query = [],
        string $body = '',
        string $ip = '',
        string $accept = '',
        ?array $parsed = null,
        string $authorization = '',
    ): Response {
        try {
            return $this->dispatch(strtoupper($method), $path, $query, $body, $ip, $accept, $parsed, $authorization);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $path));
        }
    }

    /** Ask a resource or a page. @param array<string,mixed> $query */
    public function get(string $path, array $query = []): Response
    {
        return $this->route('GET', $path, $query);
    }

    /** Make a resource, or call an endpoint with its body. @param array<string,mixed>|string $body a JSON:API document, or the body as it is */
    public function post(string $path, array|string $body = '', array $query = []): Response
    {
        return $this->route('POST', $path, $query, is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);
    }

    /** Change a resource. @param array<string,mixed>|string $body a JSON:API document, or the body as it is */
    public function patch(string $path, array|string $body): Response
    {
        return $this->route('PATCH', $path, [], is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);
    }

    public function delete(string $path): Response
    {
        return $this->route('DELETE', $path);
    }

    /** Once the answer is sent: what waited for it (a simulator that registered is read) and the tasks that are due. */
    public function terminate(): void
    {
        $this->api->finish();
    }

    /**
     * @param array<string,mixed> $query
     * @param ?array<string,mixed> $parsed
     */
    private function dispatch(
        string $method,
        string $path,
        array $query,
        string $body,
        string $ip,
        string $accept,
        ?array $parsed,
        string $authorization,
    ): Response {
        $base = rtrim($this->base, '/');
        if ($base !== '' && !($path === $base || str_starts_with($path, "$base/"))) {
            return Response::problem(Problem::notFound('address', $path));
        }
        $segments = array_map(
            'rawurldecode',
            array_values(array_filter(explode('/', substr($path, strlen($base))), 'strlen')),
        );

        // [allowed methods => handler], by the shape of the address
        $count = count($segments);
        $grid = ($segments[0] ?? '') === 'grids' && $count >= 3 ? $segments[1] : null;
        $tail = $grid === null ? $segments : array_slice($segments, 2);
        if (
            $grid === null &&
            $this->grid !== null &&
            (isset(Api::GRID_RESOURCES[$tail[0] ?? '']) ||
                in_array(
                    $tail[0] ?? '',
                    [
                        'destinations',
                        'motd',
                        'search',
                        'offline-messages',
                        'currency.php',
                        'landtool.php',
                        'health',
                        'addresses',
                        'info',
                        'monitor',
                    ],
                    true,
                ))
        ) {
            $grid = $this->grid;
        }
        $template = '';
        if ($segments === ['openapi.json']) {
            $template = '/openapi.json';
            $routes = ['GET' => fn() => new Response(200, ['Content-Type' => self::OPENAPI], Contract::document())];
        } elseif (count($segments) === 3 && $segments[0] === 'tasks' && $segments[2] === 'runs') {
            $template = '/tasks/{id}/runs';
            $routes = ['POST' => fn() => $this->api->run($segments[1])];
        } elseif (
            $grid !== null &&
            count($tail) === 2 &&
            $tail[0] === 'assets' &&
            preg_match('/^(.+)\.(' . implode('|', array_keys(Image::FORMATS)) . ')$/', $tail[1], $m) === 1
        ) {
            $width = isset($query['width']) ? (int) $query['width'] : null;
            $template = '/grids/{grid}/assets/{id}.{format}';
            $routes = ['GET' => fn() => $this->api->media($grid, $m[1], $m[2], $this->converter, $width)];
        } elseif ($tail === ['text-images']) {
            $template = '/text-images';
            $routes = ['GET' => fn() => $this->api->textImage($query)];
        } elseif ($grid !== null && $tail === ['search', 'register']) {
            $template = '/grids/{grid}/search/register';
            $routes = ['GET' => fn() => $this->api->register($grid, $query)];
        } elseif ($grid !== null && $tail === ['search', 'query']) {
            $template = '/grids/{grid}/search/query';
            $routes = ['POST' => fn() => $this->api->searchQuery($grid, $body, $query)];
        } elseif ($grid !== null && count($tail) === 2 && $tail[0] === 'offline-messages') {
            $template = '/grids/{grid}/offline-messages/' . $tail[1];
            $routes = ['POST' => fn() => $this->api->offline($grid, $tail[1], $body)];
        } elseif ($grid !== null && $tail === ['currency.php']) {
            $template = '/grids/{grid}/currency.php';
            $routes = [
                'POST' => fn() => $this->api->currency($grid, $body, $ip),
            ];
        } elseif ($grid !== null && $tail === ['landtool.php']) {
            $template = '/grids/{grid}/landtool.php';
            $routes = [
                'POST' => fn() => $this->api->landTool($grid, $body, $ip),
            ];
        } elseif ($grid !== null && $tail === ['addresses']) {
            $template = '/grids/{grid}/addresses';
            $routes = ['GET' => fn() => $this->api->addresses($grid)];
        } elseif ($grid !== null && $tail === ['info']) {
            $template = '/grids/{grid}/info';
            $routes = ['GET' => fn() => $this->api->info($grid)];
        } elseif ($grid !== null && $tail === ['monitor']) {
            $template = '/grids/{grid}/monitor';
            $routes = ['GET' => fn() => $this->api->monitor($grid, $query)];
        } elseif ($grid !== null && $tail === ['health']) {
            $template = '/grids/{grid}/health';
            $routes = ['GET' => fn() => $this->api->health($grid)];
        } elseif ($grid !== null && $tail === ['motd']) {
            $template = '/grids/{grid}/motd';
            $routes = ['GET' => fn() => $this->api->motd($grid, (string) ($query['username'] ?? ''))];
        } elseif ($grid === null && $segments === ['setups', 'quick']) {
            $template = '/setups/quick';
            $routes = [
                'POST' => fn() => $this->check($body, $parsed, $path, 'setups') ??
                    $this->api->quickSetup($this->attributes($body, $parsed)),
            ];
        } elseif ($grid === null && $segments === ['setups', 'steps']) {
            $template = '/setups/steps';
            $routes = [
                'POST' => fn() => $this->check($body, $parsed, $path, 'steps') ??
                    $this->api->setupStep($this->attributes($body, $parsed)),
            ];
        } elseif (count($tail) === 1 && $tail[0] !== '') {
            $type = $tail[0];
            $template = ($grid !== null ? '/grids/{grid}' : '') . "/$type";
            // The boards of the scripts ask the guide as the lines they read
            $type === 'destinations' && str_contains($accept, 'text/plain') && ($query['format'] = 'list');
            $routes = [
                'GET' => fn() => $this->api->list($type, $query, $grid),
                'POST' => fn() => $this->check($body, $parsed, $path, $type) ??
                    $this->api->create($type, $this->attributes($body, $parsed), $grid),
            ];
        } elseif (count($tail) === 2) {
            [$type, $id] = $tail;
            $template = ($grid !== null ? '/grids/{grid}' : '') . "/$type/{id}";
            $routes = [
                'GET' => fn() => $this->api->get($type, $id, $grid),
                'PATCH' => fn() => $this->check($body, $parsed, $path, $type) ??
                    $this->api->update($type, $id, $this->attributes($body, $parsed), $grid),
                'DELETE' => fn() => $this->api->delete($type, $id, $grid),
            ];
        } else {
            return Response::problem(Problem::notFound('address', $path));
        }
        if (!isset($routes[$method])) {
            $allow = implode(', ', array_keys($routes));

            return new Response(
                405,
                ['Content-Type' => Problem::CONTENT_TYPE, 'Allow' => $allow],
                (new Problem(
                    405,
                    'Method not allowed',
                    "$method is not allowed here, $allow are",
                    instance: $path,
                ))->toArray(),
            );
        }

        if (
            $this->authenticator !== null &&
            !in_array("$method $template", self::PUBLIC, true) &&
            !$this->authenticator->allows(ApiKeys::token($authorization))
        ) {
            return new Response(
                401,
                ['Content-Type' => Problem::CONTENT_TYPE, 'WWW-Authenticate' => 'Bearer realm="opensim"'],
                (new Problem(
                    401,
                    'Unauthorized',
                    'This needs an API key: Authorization: Bearer <key> (opensim api-key create makes one)',
                    instance: $path,
                ))->toArray(),
            );
        }

        return $routes[$method]();
    }

    /** The problem that says the body of a request is wrong, null when it is fine. */
    private function check(string $body, ?array $parsed, string $path, string $type): ?Response
    {
        $document = $this->document($body, $parsed);
        if ($document === null) {
            return Response::problem(
                new Problem(400, 'Bad request', 'The body is not a JSON document', instance: $path),
            );
        }
        if ($document !== [] && !is_array($document['data'] ?? null)) {
            return Response::problem(new Problem(400, 'Bad request', "The document has no 'data'", instance: $path));
        }
        $given = $document['data']['type'] ?? $type;
        if ($given !== $type) {
            return Response::problem(
                new Problem(
                    409,
                    'Conflict',
                    "The resource is of type '$given', this address takes '$type'",
                    instance: $path,
                ),
            );
        }

        return null;
    }

    /** @return array<string,mixed> the attributes of the resource in the body */
    private function attributes(string $body, ?array $parsed): array
    {
        $attributes = $this->document($body, $parsed)['data']['attributes'] ?? [];

        return is_array($attributes) ? $attributes : [];
    }

    /** @return ?array<string,mixed> the JSON document of the body, empty when there is none, null when it is not one */
    private function document(string $body, ?array $parsed): ?array
    {
        if ($parsed !== null) {
            return $parsed;
        }
        $raw = trim($body);
        if ($raw === '') {
            return [];
        }
        $document = json_decode($raw, true);

        return is_array($document) ? $document : null;
    }
}
