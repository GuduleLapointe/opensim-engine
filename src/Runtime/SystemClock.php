<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * The real time.
 */
final class SystemClock implements ClockInterface
{
    public function sleep(float $seconds): void
    {
        usleep((int) round($seconds * 1_000_000));
    }

    public function time(): float
    {
        return microtime(true);
    }
}
