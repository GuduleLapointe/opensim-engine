<?php

declare(strict_types=1);

namespace OpenSim\Data;

/**
 * Where a Cache keeps what must outlive the request: a table of a database, a folder of files. A store never throws: what it cannot
 * read is not there, what it cannot write is not kept.
 */
interface CacheStoreInterface
{
    /** What tells this store from another one (the database or the folder), for what a Cache keeps in memory. */
    public function scope(): string;

    /**
     * @param int $now the time, in seconds
     * @return ?array{value:mixed,expires:int} null when it is not there or not valid any more; expires 0 when it never is
     */
    public function get(string $key, int $now): ?array;

    /** @param int $expires the time it is not valid any more, 0 for never */
    public function put(string $key, mixed $value, int $expires): void;

    public function forget(string $key): void;
}
