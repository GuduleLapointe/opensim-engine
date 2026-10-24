<?php

declare(strict_types=1);

namespace OpenSim\Container;

/** The result of a Podman command. */
final class CommandResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 0;
    }
}
