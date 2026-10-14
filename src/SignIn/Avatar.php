<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

/**
 * An avatar a grid recognised, as the grid describes it.
 */
final class Avatar
{
    public function __construct(
        public readonly string $id,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email = '',
        public readonly int $level = 0,
        public readonly int $flags = 0,
        public readonly string $title = '',
        /** The grid of the avatar when it is not the one that answered. */
        public readonly ?string $grid = null,
    ) {}

    public function fullName(): string
    {
        return "{$this->firstName} {$this->lastName}";
    }
}
