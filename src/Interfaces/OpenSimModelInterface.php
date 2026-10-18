<?php

declare(strict_types=1);

namespace OpenSim\Interfaces;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;
use OpenSim\Exceptions\ChannelUnavailable;

/**
 * The models read in the databases of a grid (avatars, regions, parcels, estates): listed and found in the config of a grid, which
 * says where its databases are.
 */
interface OpenSimModelInterface extends ModelInterface
{
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
