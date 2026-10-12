<?php

declare(strict_types=1);

namespace OpenSim\Moderation;

/**
 * A user to ban: a local account, or a visitor of another grid (the grid they come from is given).
 */
final class BannedUser
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $first = '',
        public readonly string $last = '',
        public readonly string $grid = '',
    ) {
    }

    /** First.Last@grid as a viewer shows it, the UUID for a user whose name is not known. */
    public function label(): string
    {
        if ($this->first === '' && $this->last === '') {
            return $this->uuid;
        }

        return "{$this->first}.{$this->last}" . ($this->grid !== '' ? "@{$this->grid}" : '');
    }
}
