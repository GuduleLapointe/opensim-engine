<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

/**
 * The address to send a person to, and the `state` (plain values) that complete() needs when they come back.
 */
final class SignInRedirect
{
    /**
     * @param string $url where to send the person
     * @param array<string,string> $state what complete() needs
     */
    public function __construct(public readonly string $url, public readonly array $state) {}

    /** The redirect that goes with a state that was kept. */
    public static function resume(array $state): self
    {
        return new self('', $state);
    }
}
