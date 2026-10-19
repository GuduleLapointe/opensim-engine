<?php

declare(strict_types=1);

namespace OpenSim\Security;

use OpenSim\Runtime\ClockInterface;
use OpenSim\Runtime\Environment;

/**
 * The keys that open the API: global keys, each with a label, made and revoked on the machine (`opensim api-key create|list|revoke`).
 * A key is shown once, when it is made: the file keeps its SHA-256, which tells nothing of a key as long as the key is random. The
 * file is in the state folder of the install, mode 640 for the group of the web server, which reads it.
 *
 *     $keys = ApiKeys::for($environment);
 *     ['id' => $id, 'key' => $key] = $keys->create('manager');
 *     $keys->allows($key);       // true
 *     $keys->revoke($id);
 *
 * A key is `osk_` and 43 characters; it is presented as `Authorization: Bearer <key>`.
 */
final class ApiKeys implements Authenticator
{
    public const PREFIX = 'osk_';

    public function __construct(private readonly string $file, private readonly ?ClockInterface $clock = null) {}

    /** The keys of an install. */
    public static function for(Environment $environment): self
    {
        return new self(rtrim($environment->layout->stateRoot(), '/') . '/api-keys.json', $environment->clock);
    }

    /**
     * Make a key.
     *
     * @return array{id:string,key:string} the key is not kept, say it now
     */
    public function create(string $label): array
    {
        $key = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = substr(bin2hex(random_bytes(4)), 0, 8);
        $keys = $this->read();
        $keys[] = [
            'id' => $id,
            'label' => trim($label) !== '' ? trim($label) : $id,
            'hash' => hash('sha256', $key),
            'created' => $this->now(),
            'revoked' => null,
        ];
        $this->write($keys);

        return ['id' => $id, 'key' => $key];
    }

    /**
     * The keys, without what opens them.
     *
     * @return list<array{id:string,label:string,created:int,revoked:?int}>
     */
    public function all(): array
    {
        return array_map(
            static fn(array $key): array => [
                'id' => $key['id'],
                'label' => $key['label'],
                'created' => $key['created'],
                'revoked' => $key['revoked'],
            ],
            $this->read(),
        );
    }

    /** Revoke a key by its id (or its label): it opens nothing any more. Whether there was a key to revoke. */
    public function revoke(string $id): bool
    {
        $keys = $this->read();
        $done = false;
        foreach ($keys as $index => $key) {
            if ($key['revoked'] === null && ($key['id'] === $id || $key['label'] === $id)) {
                $keys[$index]['revoked'] = $this->now();
                $done = true;
            }
        }
        $done && $this->write($keys);

        return $done;
    }

    public function allows(?string $token): bool
    {
        if ($token === null || !str_starts_with($token, self::PREFIX)) {
            return false;
        }
        $hash = hash('sha256', $token);
        foreach ($this->read() as $key) {
            if ($key['revoked'] === null && hash_equals($key['hash'], $hash)) {
                return true;
            }
        }

        return false;
    }

    /** The key a header presents: `Bearer <key>`. */
    public static function token(string $authorization): ?string
    {
        return preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $authorization, $m) === 1 ? $m[1] : null;
    }

    /** @return list<array{id:string,label:string,hash:string,created:int,revoked:?int}> */
    private function read(): array
    {
        $kept = is_file($this->file) ? json_decode((string) @file_get_contents($this->file), true) : null;

        return array_values(
            array_filter(
                is_array($kept['keys'] ?? null) ? $kept['keys'] : [],
                static fn(mixed $key): bool => is_array($key) && isset($key['id'], $key['hash']),
            ),
        );
    }

    /** @param list<array<string,mixed>> $keys */
    private function write(array $keys): void
    {
        $directory = dirname($this->file);
        is_dir($directory) || @mkdir($directory, 0o750, true);
        $json = json_encode(['keys' => $keys], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($this->file, $json, LOCK_EX) === false) {
            throw new \RuntimeException("cannot write the keys in {$this->file}");
        }
        @chmod($this->file, 0o640);
    }

    private function now(): int
    {
        return (int) ($this->clock?->time() ?? time());
    }
}
