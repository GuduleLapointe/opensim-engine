<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Controllers\Controller;

/**
 * One instance of the status: enabled on this machine, running or not.
 */
final class InstanceStatus
{
    public function __construct(
        public readonly Controller $instance,
        public readonly ?Process $process,
    ) {
    }

    public function name(): string
    {
        return $this->instance->name();
    }

    public function type(): string
    {
        return $this->instance->type();
    }

    public function isRunning(): bool
    {
        return $this->process !== null;
    }
}
