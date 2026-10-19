<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Controllers\AbstractController;

/**
 * The instances of this machine and what they use: the enabled ones, running or not, and the totals of the OpenSimulator
 * processes that run.
 */
final class Status
{
    /**
     * @param list<AbstractController> $instances
     * @param list<Process> $processes every OpenSimulator process of the machine, enabled instances or not
     */
    public function __construct(public readonly array $instances, public readonly array $processes) {}

    public function up(): int
    {
        return count(array_filter($this->instances, static fn(AbstractController $i): bool => $i->process() !== null));
    }

    public function down(): int
    {
        return count($this->instances) - $this->up();
    }

    /** @return array{cpu:float,memory:float,rss:int} */
    public function totals(): array
    {
        return [
            'cpu' => array_sum(array_map(static fn(Process $p): float => $p->cpu, $this->processes)),
            'memory' => array_sum(array_map(static fn(Process $p): float => $p->memory, $this->processes)),
            'rss' => array_sum(array_map(static fn(Process $p): int => $p->rss, $this->processes)),
        ];
    }
}
