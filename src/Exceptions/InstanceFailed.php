<?php

declare(strict_types=1);

namespace OpenSim\Exceptions;

/**
 * An instance that could not be started or stopped: the message says why.
 */
final class InstanceFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $instance,
        string $message,
    ) {
        parent::__construct($message);
    }
}
