<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

/**
 * Checks the name and the password of an avatar with the grid itself, never in its database.
 */
interface AvatarAuthenticator
{
    public function authenticate(string $name, string $password): SignInResult;

    /** Whether a success also opens a session of the avatar in the world: what a sign-in for a web page must not do. */
    public function opensSession(): bool;
}
