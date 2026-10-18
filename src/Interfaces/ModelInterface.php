<?php

declare(strict_types=1);

namespace OpenSim\Interfaces;

use OpenSim\Exceptions\ChannelUnavailable;

/**
 * What every model of the engine offers, whatever it comes from (a table, a file, an instance), so that any application (the manager, a
 * plugin, a command, another CMS) uses them the same way. The names are the standard ones: each model maps its own columns to
 * them (a UUID is `PrincipalID` for an avatar, `uuid` for a region, `RegionUUID` for a parcel).
 *
 * A write goes through a channel (the database, the console of a simulator, an ini file) and is refused with ChannelUnavailable
 * when no channel can do it.
 *
 * Every model lists (`all()`) and finds (`find()`) its records, the process being its own: the ones read in a database of a grid
 * are given its config (OpenSimModelInterface), the ones of the machine, grids and instances, its environment
 * (InstalledModelInterface).
 */
interface ModelInterface extends Enablable
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
    public function delete(): void;
}
