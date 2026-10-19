<?php

declare(strict_types=1);

namespace OpenSim\Exceptions;

/**
 * What is asked for is not there: a grid that is not known here, a resource that does not exist.
 */
final class NotFound extends \RuntimeException
{
    public static function for(string $what): self
    {
        return new self("There is no $what.");
    }
}
