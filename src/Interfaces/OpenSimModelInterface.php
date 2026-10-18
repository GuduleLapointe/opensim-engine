<?php

declare(strict_types=1);

namespace OpenSim\Interfaces;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;

/**
 * What every model of the engine offers, whatever the table or the file it comes from, so that any application (the manager, a
 * plugin, a command, another CMS) uses them the same way. The names are the standard ones: each model maps its own columns to
 * them (a UUID is `PrincipalID` for an avatar, `uuid` for a region, `RegionUUID` for a parcel).
 *
 * A write goes through a channel (the database, the console of a simulator, an ini file) and is refused with ChannelUnavailable
 * when no channel can do it.
 */
interface OpenSimModelInterface
{
    /** What identifies it in its table or its file. */
    public function id(): string|int|null;

    /** What is shown: "Ada Love", "Welcome". */
    public function name(): string;

    /** The name made for an address: "ada-love". */
    public function slug(): string;

    public function uuid(): ?string;

    /** Where it is reached, built from the rest: `host:port` for a grid, `first.last@host:port` for an avatar. */
    public function uri(): ?string;

    /**
     * Every field of the record, under the standard names and its own.
     *
     * @return array<string,mixed>
     */
    public function detail(): array;

    /** The UUID of its owner, null for what nobody owns. */
    public function owner(): ?string;

    /** The UUID of the group that owns it, null when no group does. */
    public function group(): ?string;

    /** The bitwise flags (the constants are in the class). */
    public function flags(): int;

    /** When it was last there, null when it never was or when the model has no such date. */
    public function lastSeen(): ?\DateTimeImmutable;

    public function isEnabled(): bool;

    /**
     * The channels a write can go through now.
     *
     * @return list<string> database, console, ini
     */
    public function channels(): array;

    /**
     * @param array<string,mixed> $values standard names
     * @throws ChannelUnavailable
     */
    public function update(array $values): void;

    /** @throws ChannelUnavailable */
    public function enable(): void;

    /** @throws ChannelUnavailable */
    public function disable(): void;

    /** @throws ChannelUnavailable */
    public function delete(): void;

    /**
     * @param array<string,mixed> $values standard names
     * @throws ChannelUnavailable
     */
    public static function create(GridConfig $config, array $values): static;

    /**
     * The list: search, sort, filters and pages are options, the collection says what was applied.
     *
     * @param array{search?:string,sort?:string,filters?:array<string,string>,page?:int,perPage?:int} $options
     */
    public static function all(GridConfig $config, array $options = []): Collection;

    public static function find(GridConfig $config, string $id): ?static;
}
