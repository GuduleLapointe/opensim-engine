<?php

declare(strict_types=1);

namespace OpenSim\Security;

/**
 * Lets everyone in: for what only the operator can reach, the command line and the PHP library of a machine (the files of the install
 * are already protected by the system), and for tests. Never for a web host.
 */
final class OpenAccess implements Authenticator
{
    public function allows(?string $token): bool
    {
        return true;
    }
}
