<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * Says nothing: for whoever uses the result only.
 */
final class NullReporter implements ReporterInterface
{
    public function info(string $message): void
    {
    }

    public function error(string $message): void
    {
    }

    public function debug(string $message): void
    {
    }

    public function step(string $text): void
    {
    }

    public function stepEnd(): void
    {
    }

    public function isTerminal(): bool
    {
        return false;
    }
}
