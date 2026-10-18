<?php

declare(strict_types=1);

namespace OpenSim\Interfaces;

use OpenSim\Data\Collection;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Runtime\Environment;

/**
 * The models of the machine (grids, instances): listed and found in its environment, which says where the install is, its processes,
 * its launcher. Their records are folders and config files, and the processes that run from them.
 */
interface InstalledModelInterface extends ModelInterface
{
    /**
     * @param array<string,mixed> $values standard names
     * @throws ChannelUnavailable
     */
    public static function create(Environment $environment, array $values): static;

    /**
     * The list: search, sort, filters and pages are options, the collection says what was applied.
     *
     * @param array{search?:string,sort?:string,filters?:array<string,string>,page?:int,perPage?:int} $options
     */
    public static function all(Environment $environment, array $options = []): Collection;

    public static function find(Environment $environment, string $id): ?static;
}
