<?php

declare(strict_types=1);

namespace OpenSim\Security;

/**
 * Lets no one in: what a web host has until it is given the keys of the install (`ApiKeys`). The API is closed by default.
 */
final class Locked implements Authenticator
{
    public function allows(?string $token): bool
    {
        return false;
    }
}
