<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * A reporter that can show what a wait is on right now, on a line that the next step replaces (a terminal), or not at all.
 */
interface PendingReporterInterface extends ReporterInterface
{
    /** What is being waited for; the next call of step() or pending() takes its place. */
    public function pending(string $text): void;
}
