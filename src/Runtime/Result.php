<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * The result of starting or stopping an instance: what became of it, and why when it failed.
 */
final class Result
{
    public function __construct(
        public readonly string $instance,
        public readonly Outcome $outcome,
        public readonly string $message = '',
    ) {
    }

    /** Whether nothing went wrong (an instance that is still loading did not fail). */
    public function ok(): bool
    {
        return $this->outcome->ok();
    }

    public static function failed(string $instance, string $message): self
    {
        return new self($instance, Outcome::Failed, $message);
    }
}
