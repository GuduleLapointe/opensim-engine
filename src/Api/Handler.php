<?php

declare(strict_types=1);

namespace OpenSim\Api;

use OpenSim\Assets\ConverterInterface;
use OpenSim\Models\Image;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The API as a PSR-15 handler: any framework or script that speaks PSR-7 mounts it and gives it the requests; the engine chooses
 * no implementation of the messages, it is given the factories of the one the application uses.
 *
 *     $handler = new Handler(new Api($environment, $scheduler), $psr17, $psr17, base: '/api');
 *     $response = $handler->handle($request);
 *
 * The addresses are the ones of the contract (see Contract::document()): `/{type}` and `/{type}/{id}` for the resources of the
 * machine, `/grids/{grid}/{type}` and `/grids/{grid}/{type}/{id}` for the ones of a grid, `/grids/{grid}/assets/{id}.{format}`
 * for a picture, `/grids/{grid}/motd`, `/text-images`, `POST /tasks/{id}/runs`, and the contract itself at `/openapi.json`. A host that
 * serves one grid says so (`grid:`) and asks for its resources without it (`/motd`, `/destinations`, `/avatars`). The body of a creation or a change is a
 * JSON:API document, the errors are problems (RFC 9457).
 */
final class Handler implements RequestHandlerInterface
{
    /** The content type of the contract. */
    public const OPENAPI = 'application/vnd.oai.openapi+json';

    /**
     * @param string $base where the application mounts the API (`/api`): the beginning of the paths it does not answer for
     * @param ?ConverterInterface $converter converts the pictures of the grid (default: the one of the machine)
     * @param ?string $grid the grid a web host serves: what is asked of a grid without saying which (`/motd`, `/destinations`,
     *        `/avatars`, `/assets/{id}.png`) is asked of this one
     */
    public function __construct(
        private readonly Api $api,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly string $base = '',
        private readonly ?ConverterInterface $converter = null,
        private readonly ?string $grid = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $answer = $this->dispatch($request);
        } catch (\Throwable $error) {
            $answer = Response::problem(Problem::from($error, $request->getUri()->getPath()));
        }
        $response = $this->responses->createResponse($answer->status);
        foreach ($answer->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $answer->status === 204 ? $response : $response->withBody($this->streams->createStream($answer->body()));
    }

    private function dispatch(ServerRequestInterface $request): Response
    {
        $path = $request->getUri()->getPath();
        $base = rtrim($this->base, '/');
        if ($base !== '' && !($path === $base || str_starts_with($path, "$base/"))) {
            return Response::problem(Problem::notFound('address', $path));
        }
        $segments = array_map(
            'rawurldecode',
            array_values(array_filter(explode('/', substr($path, strlen($base))), 'strlen')),
        );
        $method = strtoupper($request->getMethod());
        $query = $request->getQueryParams();

        // [allowed methods => handler], by the shape of the address
        $count = count($segments);
        $grid = ($segments[0] ?? '') === 'grids' && $count >= 3 ? $segments[1] : null;
        $tail = $grid === null ? $segments : array_slice($segments, 2);
        if (
            $grid === null &&
            $this->grid !== null &&
            (isset(Api::GRID_RESOURCES[$tail[0] ?? '']) || in_array($tail[0] ?? '', ['destinations', 'motd'], true))
        ) {
            $grid = $this->grid;
        }
        if ($segments === ['openapi.json']) {
            $routes = ['GET' => fn() => new Response(200, ['Content-Type' => self::OPENAPI], Contract::document())];
        } elseif (count($segments) === 3 && $segments[0] === 'tasks' && $segments[2] === 'runs') {
            $routes = ['POST' => fn() => $this->api->run($segments[1])];
        } elseif (
            $grid !== null &&
            count($tail) === 2 &&
            $tail[0] === 'assets' &&
            preg_match('/^(.+)\.(' . implode('|', array_keys(Image::FORMATS)) . ')$/', $tail[1], $m) === 1
        ) {
            $width = isset($query['width']) ? (int) $query['width'] : null;
            $routes = ['GET' => fn() => $this->api->media($grid, $m[1], $m[2], $this->converter, $width)];
        } elseif ($tail === ['text-images']) {
            $routes = ['GET' => fn() => $this->api->textImage($query)];
        } elseif ($grid !== null && $tail === ['motd']) {
            $routes = ['GET' => fn() => $this->api->motd($grid, (string) ($query['username'] ?? ''))];
        } elseif (count($tail) === 1 && $tail[0] !== '') {
            $type = $tail[0];
            $routes = [
                'GET' => fn() => $this->api->list($type, $query, $grid),
                'POST' => fn() => $this->body($request, $type) ??
                    $this->api->create($type, $this->attributes($request), $grid),
            ];
        } elseif (count($tail) === 2) {
            [$type, $id] = $tail;
            $routes = [
                'GET' => fn() => $this->api->get($type, $id, $grid),
                'PATCH' => fn() => $this->body($request, $type) ??
                    $this->api->update($type, $id, $this->attributes($request), $grid),
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

        return $routes[$method]();
    }

    /** The problem that says the body of a request is wrong, null when it is fine. */
    private function body(ServerRequestInterface $request, string $type): ?Response
    {
        $document = $this->document($request);
        $path = $request->getUri()->getPath();
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
    private function attributes(ServerRequestInterface $request): array
    {
        $attributes = $this->document($request)['data']['attributes'] ?? [];

        return is_array($attributes) ? $attributes : [];
    }

    /** @return ?array<string,mixed> the JSON document of the body, empty when there is none, null when it is not one */
    private function document(ServerRequestInterface $request): ?array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            return $parsed;
        }
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            return [];
        }
        $document = json_decode($raw, true);

        return is_array($document) ? $document : null;
    }
}
