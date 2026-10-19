<?php

declare(strict_types=1);

namespace OpenSim\Services;

/**
 * A request a service cannot answer: the status to give and what to tell. The message is meant for whoever asked.
 */
final class Failure extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
