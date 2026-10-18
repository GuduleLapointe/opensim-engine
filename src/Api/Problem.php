<?php

declare(strict_types=1);

namespace OpenSim\Api;

use OpenSim\Exceptions\ChannelUnavailable;

/**
 * What went wrong, told as RFC 9457 asks (`application/problem+json`): a status, a title, what happened, and where. The same
 * problem for the command line, the HTTP API and the PHP library: the exception says it, the adapter shows it.
 */
final class Problem
{
    public const CONTENT_TYPE = 'application/problem+json';

    /**
     * @param array<string,mixed> $extensions members of its own, beside the standard ones
     */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly string $detail = '',
        public readonly string $type = 'about:blank',
        public readonly ?string $instance = null,
        public readonly array $extensions = [],
    ) {
    }

    /** What is asked is not there. */
    public static function notFound(string $what, ?string $instance = null): self
    {
        return new self(404, 'Not found', "There is no $what.", 'urn:opensim:problem:not-found', $instance);
    }

    /** The problem an exception stands for. A failure of the server does not tell its message: it can hold a path or a password. */
    public static function from(\Throwable $error, ?string $instance = null): self
    {
        return match (true) {
            $error instanceof \InvalidArgumentException => new self(400, 'Invalid request', $error->getMessage(), 'urn:opensim:problem:invalid-request', $instance),
            $error instanceof ChannelUnavailable => new self(503, 'Not available', $error->getMessage(), 'urn:opensim:problem:channel-unavailable', $instance),
            default => new self(500, 'Internal error', '', 'about:blank', $instance),
        };
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter(
            ['type' => $this->type, 'title' => $this->title, 'status' => $this->status, 'detail' => $this->detail, 'instance' => $this->instance] + $this->extensions,
            static fn($value): bool => $value !== null && $value !== '',
        );
    }

    public function json(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
