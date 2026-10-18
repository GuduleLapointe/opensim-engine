<?php

declare(strict_types=1);

namespace OpenSim\Scheduling;

/**
 * What came of a task asked to run.
 */
final class TaskResult
{
    /** The task ran and said nothing went wrong. */
    public const RAN = 'ran';

    /** The task ran and failed: the message says why. */
    public const FAILED = 'failed';

    /** The task is not due. */
    public const NOT_DUE = 'not due';

    /** The task is running already, started by someone else. */
    public const RUNNING = 'running';

    public function __construct(
        public readonly string $task,
        public readonly string $status,
        public readonly string $message = '',
        public readonly float $duration = 0.0,
    ) {
    }

    /** Whether the task was done without failing. */
    public function ok(): bool
    {
        return $this->status === self::RAN;
    }
}
