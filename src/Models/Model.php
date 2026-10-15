<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\GridConfig;

/**
 * What the models of the engine have in common: a record read from the databases or the files of a grid, and the defaults of what a
 * model does not do (no owner, no flags, no channel to write through: a write is refused).
 */
abstract class Model implements OpenSimModelInterface
{
    /**
     * @param array<string,mixed> $row the record as its table gives it
     */
    final public function __construct(protected readonly GridConfig $config, protected readonly array $row)
    {
    }

    public function uuid(): ?string
    {
        return null;
    }

    public function uri(): ?string
    {
        return null;
    }

    public function owner(): ?string
    {
        return null;
    }

    public function group(): ?string
    {
        return null;
    }

    public function flags(): int
    {
        return 0;
    }

    public function lastSeen(): ?\DateTimeImmutable
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function channels(): array
    {
        return [];
    }

    public function update(array $values): void
    {
        throw ChannelUnavailable::for('change ' . $this->name());
    }

    public function enable(): void
    {
        throw ChannelUnavailable::for('enable ' . $this->name());
    }

    public function disable(): void
    {
        throw ChannelUnavailable::for('disable ' . $this->name());
    }

    public function delete(): void
    {
        throw ChannelUnavailable::for('delete ' . $this->name());
    }

    public static function create(GridConfig $config, array $values): static
    {
        throw ChannelUnavailable::for('create a ' . strtolower((new \ReflectionClass(static::class))->getShortName()));
    }

    /** A date from a Unix time, null when there is none (0, empty, not a number). */
    protected static function date(mixed $time): ?\DateTimeImmutable
    {
        $time = is_numeric($time) ? (int) $time : 0;

        return $time > 0 ? new \DateTimeImmutable('@' . $time) : null;
    }
}
