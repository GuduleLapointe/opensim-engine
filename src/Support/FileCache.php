<?php

declare(strict_types=1);

namespace OpenSim\Support;

use OpenSim\Runtime\ClockInterface;

/**
 * A folder of files kept for a while: data, with what is known of it (the data of an asset as the grid gave it or converted, the answer of a grid). A file
 * that is empty or older than the time to live is not kept.
 */
final class FileCache
{
    public function __construct(
        private readonly string $directory,
        private readonly int $ttl = 86400,
        private readonly ?ClockInterface $clock = null,
    ) {}

    /**
     * @return ?array{data:string,meta:array<string,mixed>} null when it is not there, or not any more
     */
    public function get(string $name): ?array
    {
        $file = $this->file($name);
        if (!is_file($file)) {
            return null;
        }
        $now = (int) ($this->clock?->time() ?? time());
        if (filesize($file) === 0 || (int) filemtime($file) < $now - $this->ttl) {
            @unlink($file);
            is_file("$file.meta") && @unlink("$file.meta");

            return null;
        }
        $data = file_get_contents($file);
        $meta = is_file("$file.meta") ? json_decode((string) file_get_contents("$file.meta"), true) : [];

        return is_string($data) ? ['data' => $data, 'meta' => is_array($meta) ? $meta : []] : null;
    }

    /** @param array<string,mixed> $meta what is known of the data (its type, its content type) */
    public function put(string $name, string $data, array $meta = []): bool
    {
        if (
            $data === '' ||
            !(is_dir($this->directory) || @mkdir($this->directory, 0o775, true) || is_dir($this->directory))
        ) {
            return false;
        }
        $file = $this->file($name);
        if (@file_put_contents($file, $data) === false) {
            return false;
        }
        @touch($file, (int) ($this->clock?->time() ?? time()));
        $meta !== [] && @file_put_contents("$file.meta", json_encode($meta));

        return true;
    }

    private function file(string $name): string
    {
        return $this->directory . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    }
}
