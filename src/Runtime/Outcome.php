<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * What became of an instance a start or a stop was asked for, when it did not fail (see InstanceFailed).
 */
enum Outcome: string
{
    /** It came up and is ready. */
    case Started = 'started';
    /** It was running already. */
    case Running = 'running';
    /** It started and is still loading: nothing is wrong, check its status later. */
    case Starting = 'starting';
    /** It stopped. */
    case Stopped = 'stopped';
    /** It was not running. */
    case NotRunning = 'not running';
}
