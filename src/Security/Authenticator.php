<?php

declare(strict_types=1);

namespace OpenSim\Security;

/**
 * Decides whether the caller of the API may use what is not public: the key it presents (`Authorization: Bearer <key>`).
 */
interface Authenticator
{
    /** @param ?string $token what the caller presented, null when it presented nothing */
    public function allows(?string $token): bool;
}
