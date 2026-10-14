<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

/**
 * The answer to a sign-in: granted with the avatar, refused (unknown avatar or wrong password, alike), or unavailable.
 */
final class SignInResult
{
    public const GRANTED = 'granted';
    public const REFUSED = 'refused';
    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly string $status,
        public readonly ?Avatar $avatar = null,
        /** What was wrong when the grid is unavailable. */
        public readonly string $reason = '',
        /** What more the grid told when it granted the sign-in. */
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
