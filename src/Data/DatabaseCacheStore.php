<?php

declare(strict_types=1);

namespace OpenSim\Data;

/**
 * A Cache store in the `oshelpers_cache` table of a database: the one the helpers keep their answers in, with the same columns and
 * the same values (JSON), so what one writes the others read. The table is made when it is not there. A database that fails is left
 * alone for the rest of the session.
 */
final class DatabaseCacheStore implements CacheStoreInterface
{
    private const TABLE = 'oshelpers_cache';

    private bool $ready = false;
    private bool $failed = false;

    public function __construct(private readonly OSPDO $pdo) {}

    public function scope(): string
    {
        return 'database:' . spl_object_id($this->pdo) . ':' . ($this->pdo->db_name ?? '');
    }

    public function get(string $key, int $now): ?array
    {
        return $this->guard(function () use ($key, $now): ?array {
            $query = $this->pdo->prepare(
                'SELECT cache_value, cache_expires FROM ' .
                    self::TABLE .
                    ' WHERE cache_key = :key AND (cache_expires IS NULL OR cache_expires = 0 OR cache_expires > :now)',
            );
            $query->execute(['key' => $key, 'now' => $now]);
            $row = $query->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['cache_value'] === null) {
                return null;
            }
            $raw = is_resource($row['cache_value']) ? stream_get_contents($row['cache_value']) : (string) $row['cache_value'];
            // What a helper stored is JSON; anything else is given as it is
            $value = json_decode((string) $raw, true);

            return [
                'value' => json_last_error() === JSON_ERROR_NONE ? $value : $raw,
                'expires' => (int) $row['cache_expires'],
            ];
        });
    }

    public function put(string $key, mixed $value, int $expires): void
    {
        $this->guard(function () use ($key, $value, $expires): void {
            $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE cache_key = :key')->execute(['key' => $key]);
            try {
                $this->pdo
                    ->prepare(
                        'INSERT INTO ' .
                            self::TABLE .
                            ' (cache_key, cache_value, cache_expires) VALUES (:key, :value, :expires)',
                    )
                    ->execute([
                        'key' => $key,
                        'value' => json_encode($value, JSON_UNESCAPED_SLASHES),
                        'expires' => $expires,
                    ]);
            } catch (\PDOException) {
                // A request that came in between wrote the same key
            }
        });
    }

    public function forget(string $key): void
    {
        $this->guard(function () use ($key): void {
            $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE cache_key = :key')->execute(['key' => $key]);
        });
    }

    /** @param \Closure():mixed $action */
    private function guard(\Closure $action): mixed
    {
        if ($this->failed || !$this->pdo->connected) {
            return null;
        }
        try {
            $this->ready || $this->create();

            return $action();
        } catch (\Throwable) {
            $this->failed = true;

            return null;
        }
    }

    private function create(): void
    {
        $this->pdo->table_exists(self::TABLE) ||
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS ' .
                    self::TABLE .
                    ' (cache_key VARCHAR(255) NOT NULL PRIMARY KEY, cache_value LONGBLOB, cache_expires INT DEFAULT 0)',
            );
        $this->ready = true;
    }
}
