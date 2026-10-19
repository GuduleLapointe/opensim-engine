<?php

declare(strict_types=1);

namespace OpenSim\Data;

/** A Cache store in a folder: a file for each key, with the time it is valid until. */
final class FileCacheStore implements CacheStoreInterface
{
    public function __construct(private readonly string $directory) {}

    public function scope(): string
    {
        return 'files:' . $this->directory;
    }

    public function get(string $key, int $now): ?array
    {
        $file = $this->file($key);
        $kept = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($kept) || !array_key_exists('value', $kept) || $kept['value'] === null) {
            return null;
        }
        $expires = (int) ($kept['expires'] ?? 0);
        if ($expires !== 0 && $expires <= $now) {
            is_file($file) && @unlink($file);

            return null;
        }

        return ['value' => $kept['value'], 'expires' => $expires];
    }

    public function put(string $key, mixed $value, int $expires): void
    {
        if (!(is_dir($this->directory) || @mkdir($this->directory, 0o775, true) || is_dir($this->directory))) {
            return;
        }
        $json = json_encode(['value' => $value, 'expires' => $expires], JSON_UNESCAPED_SLASHES);
        $json !== false && @file_put_contents($this->file($key), $json, LOCK_EX);
    }

    public function forget(string $key): void
    {
        is_file($file = $this->file($key)) && @unlink($file);
    }

    private function file(string $key): string
    {
        return $this->directory . '/' . $key . '.json';
    }
}
