<?php

declare(strict_types=1);

namespace OpenSim\Exceptions;

/** A change that no channel can make now: the database cannot be reached, the simulator is stopped, the file cannot be written. */
final class ChannelUnavailable extends \RuntimeException
{
    public static function for(string $what, string $reason = ''): self
    {
        return new self($reason !== '' ? "cannot $what: $reason" : "cannot $what: no channel is available");
    }
}
