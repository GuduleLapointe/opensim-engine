<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

/**
 * The answer to a sign-in: granted with the avatar, refused (unknown avatar or wrong password, said the same way so that nobody
 * learns which avatars exist), or unavailable when the grid could not be asked.
 */
final class SignInResult
{
    public const GRANTED = 'granted';
    public const REFUSED = 'refused';
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $status,
        public readonly ?Avatar $avatar = null,
        /** For an unavailable grid, what was wrong with it (for a log, an administrator). */
        public readonly string $reason = '',
        /** What more the grid told when it granted the sign-in (the session a login opens, for instance). */
        public readonly array $details = [],
    ) {}

    public static function granted(Avatar $avatar, array $details = []): self
    {
        return new self(self::GRANTED, $avatar, '', $details);
    }

    public static function refused(): self
    {
        return new self(self::REFUSED);
    }

    public static function unavailable(string $reason): self
    {
        return new self(self::UNAVAILABLE, null, $reason);
    }

    public function isGranted(): bool
    {
        return $this->status === self::GRANTED;
    }
}
