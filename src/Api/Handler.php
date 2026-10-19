<?php

declare(strict_types=1);

namespace OpenSim\Api;

use OpenSim\Assets\ConverterInterface;
use OpenSim\Security\Authenticator;
use OpenSim\Security\Locked;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The API as a PSR-15 handler: any framework or script that speaks PSR-7 mounts it and gives it the requests; the engine chooses
 * no implementation of the messages, it is given the factories of the one the application uses. The addresses and what they do are
 * the ones of the Router.
 *
 *     $handler = new Handler(new Api($environment, $scheduler), $psr17, $psr17, base: '/api');
 *     $response = $handler->handle($request);
 */
final class Handler implements RequestHandlerInterface
{
    /** The content type of the contract. */
    public const OPENAPI = Router::OPENAPI;

    private readonly Router $router;

    /**
     * @param string $base where the application mounts the API (`/api`): the beginning of the paths it does not answer for
     * @param ?ConverterInterface $converter converts the pictures of the grid (default: the one of the machine)
     * @param ?string $grid the grid a web host serves: what is asked of a grid without saying which (`/motd`, `/destinations`,
     *        `/avatars`, `/assets/{id}.png`) is asked of this one
     * @param ?Authenticator $authenticator who opens what is not public (`Security\ApiKeys::for($environment)`); the API is closed
     *        until it is given one
     */
    public function __construct(
        Api $api,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        string $base = '',
        ?ConverterInterface $converter = null,
        ?string $grid = null,
        ?Authenticator $authenticator = null,
    ) {
        $this->router = new Router($api, $base, $converter, $grid, $authenticator ?? new Locked());
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $answer = $this->router->route(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $request->getQueryParams(),
            (string) $request->getBody(),
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
            $request->getHeaderLine('Accept'),
            is_array($request->getParsedBody()) ? $request->getParsedBody() : null,
            $request->getHeaderLine('Authorization'),
        );
        $response = $this->responses->createResponse($answer->status);
        foreach ($answer->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $answer->status === 204 ? $response : $response->withBody($this->streams->createStream($answer->body()));
    }

    /** Once the response is sent: what waited for it (a simulator that registered is read) and the tasks that are due. */
    public function terminate(): void
    {
        $this->router->terminate();
    }
}
