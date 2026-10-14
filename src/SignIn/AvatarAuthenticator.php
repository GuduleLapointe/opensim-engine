<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

/**
 * Checks the name and the password of an avatar with its grid.
 */
interface AvatarAuthenticator
{
    public function authenticate(string $name, string $password): SignInResult;

    /** Whether a success also opens a session of the avatar in the world. */
    public function opensSession(): bool;
}
