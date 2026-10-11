<?php

declare(strict_types=1);

namespace OpenSim\Testing;

use OpenSim\Runtime\ClockInterface;

/**
 * A clock that does not wait: the time goes forward when something sleeps.
 */
final class ClockFake implements ClockInterface
{
    public float $now = 1000.0;

    /** @var list<float> */
    public array $slept = [];

    public function sleep(float $seconds): void
    {
        $this->slept[] = $seconds;
        $this->now += $seconds;
    }

    public function time(): float
    {
        return $this->now;
    }

    public function total(): float
    {
        return array_sum($this->slept);
    }
}
