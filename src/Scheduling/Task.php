<?php

declare(strict_types=1);

namespace OpenSim\Scheduling;

/**
 * Something to do now and then: a name, how often, and what to do. What it does is the business of whoever adds it (the helpers
 * read the snapshots of the simulators, the engine backs up); the scheduler only runs it when it is due.
 */
final class Task
{
    /**
     * @param string $name lower case letters, digits and hyphens: it names the state of the task on disk
     * @param int $every seconds between two runs
     * @param \Closure():mixed $run what to do; it may return a line saying what it did; it throws when it fails
     * @param string $label what the task is for, for whoever lists the tasks
     */
    public function __construct(
        public readonly string $name,
        public readonly int $every,
        public readonly \Closure $run,
        public readonly string $label = '',
    ) {
        preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $name) === 1 ||
            throw new \InvalidArgumentException("'$name' is not a name of a task (letters, digits, hyphens)");
        $every >= 1 || throw new \InvalidArgumentException("the task '$name' must run every second at least");
    }
}
