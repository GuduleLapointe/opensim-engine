<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

/**
 * Where to send a person to sign in with their grid, and what to remember until they come back: the application keeps `state`
 * (plain values, fit for a session) for this person only, and gives it to complete() with what the grid sent back. A state is used
 * once.
 */
final class SignInRedirect
{
    /**
     * @param string $url where to send the person
     * @param array<string,string> $state what complete() needs
     */
    public function __construct(
        public readonly string $url,
        public readonly array $state,
    ) {}

    /** The redirect that goes with a state that was kept. */
    public static function resume(array $state): self
    {
        return new self('', $state);
    }
}
