<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Data\Collection;
use OpenSim\Runtime\Environment;

/**
 * The archives and the backups of the grids of the machine: the inventories (`iar`) and the regions (`oar`) the consoles of the
 * simulators saved, in the data folder of their grid, and the backups of the setup, in its folder of backups. A file tells what is in
 * it by its name (see Archives\Archives): this reads the names, it opens nothing.
 *
 *     Archive::all($environment, ['filters' => ['kind' => 'oar'], 'sort' => 'modified desc']);
 *
 * They are read here; making them is the business of `opensim save|load iar|oar` and `opensim backup`, which wait for the console
 * of a simulator or for the databases.
 */
final class Archive extends InstalledModel
{
    public const IAR = 'iar';
    public const OAR = 'oar';
    public const BACKUP = 'backup';

    /** The modes a name can have between its parts and its stamp. */
    private const MODES = '(?:noassets|skipbadassets|publish|perm[A-Za-z0-9_.]*)';

    /**
     * @param array<string,mixed> $parts what the name says (user, simulator, region, assets, permissions, publish, logs, archives)
     */
    private function __construct(
        Environment $environment,
        private readonly string $grid,
        private readonly string $kind,
        private readonly string $file,
        private readonly array $parts,
    ) {
        parent::__construct($environment);
    }

    /** @param array<string,mixed> $options */
    public static function all(Environment $environment, array $options = []): Collection
    {
        $archives = [];
        $backups = rtrim($environment->layout->dataRoot(), '/') . '/backups/admin';
        $nicks = [];
        foreach (Grid::all($environment)->items() as $grid) {
            $nicks[] = $grid->nick();
            foreach ([self::IAR, self::OAR] as $kind) {
                foreach (glob(rtrim($grid->dataDirectory(), '/') . "/backups/$kind/*.$kind") ?: [] as $file) {
                    $archives[] = self::named($environment, $grid->nick(), $kind, $file);
                }
            }
        }
        // A backup is named after its grid
        usort($nicks, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach (glob("$backups/*.tar.gz") ?: [] as $file) {
            foreach ($nicks as $nick) {
                if (str_starts_with(basename($file), "$nick-")) {
                    $archives[] = self::named($environment, $nick, self::BACKUP, $file);

                    break;
                }
            }
        }

        return self::listing(
            $archives,
            $options,
            static fn(self $archive): string => "{$archive->name()} {$archive->grid} {$archive->kind}",
            [
                'modified' => static fn(self $archive): int => $archive->modified(),
                'name' => static fn(self $archive): string => $archive->name(),
                'size' => static fn(self $archive): int => $archive->size(),
                'grid' => static fn(self $archive): string => $archive->grid,
            ],
            [
                'kind' => [
                    static fn(self $archive, string $value): bool => $archive->kind === $value,
                    [self::IAR, self::OAR, self::BACKUP],
                ],
                'grid' => [static fn(self $archive, string $value): bool => $archive->grid === $value, []],
            ],
            [
                ['name' => 'name', 'label' => _('File'), 'sortable' => true],
                ['name' => 'grid', 'label' => _('Grid'), 'sortable' => true],
                ['name' => 'kind', 'label' => _('Kind')],
                ['name' => 'size', 'label' => _('Size'), 'sortable' => true],
                ['name' => 'modified', 'label' => _('Date'), 'sortable' => true],
            ],
        );
    }

    public static function find(Environment $environment, string $id): ?static
    {
        foreach (self::all($environment)->items() as $archive) {
            if ($archive->id() === $id) {
                return $archive;
            }
        }

        return null;
    }

    /** `vonda_oar_vonda-welcome-20261019-101500`: the grid, the kind and the name without its extension. */
    public function id(): string
    {
        return $this->slug();
    }

    public function slug(): string
    {
        return $this->grid . '_' . $this->kind . '_' . preg_replace('/\.(?:iar|oar|tar\.gz)$/', '', $this->name());
    }

    public function name(): string
    {
        return basename($this->file);
    }

    public function grid(): string
    {
        return $this->grid;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function size(): int
    {
        return (int) @filesize($this->file);
    }

    public function modified(): int
    {
        return (int) @filemtime($this->file);
    }

    public function lastSeen(): ?\DateTimeImmutable
    {
        return $this->modified() > 0 ? new \DateTimeImmutable('@' . $this->modified()) : null;
    }

    /** An archive is there as long as its file is. */
    public function isEnabled(): bool
    {
        return is_file($this->file);
    }

    public function enable(): bool
    {
        throw \OpenSim\Exceptions\ChannelUnavailable::for('enable an archive');
    }

    public function disable(): void
    {
        throw \OpenSim\Exceptions\ChannelUnavailable::for('disable an archive');
    }

    public function detail(): array
    {
        return [
            'id' => $this->id(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'grid' => $this->grid,
            'kind' => $this->kind,
            'file' => $this->file,
            'size' => $this->size(),
            'modified' => $this->modified() ?: null,
            'stamp' => $this->parts['stamp'] ?? null,
            'user' => $this->parts['user'] ?? null,
            'simulator' => $this->parts['simulator'] ?? null,
            'region' => $this->parts['region'] ?? null,
            'assets' => $this->parts['assets'] ?? null,
            'permissions' => $this->parts['permissions'] ?? null,
            'publish' => $this->parts['publish'] ?? null,
            'logs' => $this->parts['logs'] ?? null,
            'archives' => $this->parts['archives'] ?? null,
        ];
    }

    /** The archive of a file, with what its name says. */
    private static function named(Environment $environment, string $grid, string $kind, string $file): self
    {
        $name = basename($file);
        $parts = [];
        if (
            preg_match(
                '/^(?<head>.+?)(?<modes>(?:-' . self::MODES . ')*)-(?<stamp>\d{8}-\d{6})\.(?:iar|oar)$/',
                $name,
                $m,
            ) === 1
        ) {
            $words = explode('-', $m['head']);
            array_shift($words);
            $parts = self::modes($m['modes']) + ['stamp' => self::time($m['stamp'])];
            if ($kind === self::IAR) {
                $parts += ['user' => trim(implode(' ', array_slice($words, 0, 2)))];
            } else {
                $parts += ['simulator' => $words[0] ?? null, 'region' => $words[1] ?? null];
            }
        } elseif (
            preg_match(
                '/^(?<head>.+?)-(?<stamp>\d{8}-\d{6})(?<logs>-logs)?(?<archives>-archives)?\.tar\.gz$/',
                $name,
                $m,
            ) === 1
        ) {
            $words = explode('-', substr($m['head'], strlen($grid) + 1));
            $parts = [
                'stamp' => self::time($m['stamp']),
                'simulator' => $words[0] !== '' ? $words[0] : null,
                'logs' => ($m['logs'] ?? '') !== '',
                'archives' => ($m['archives'] ?? '') !== '',
            ];
        }

        return new self($environment, $grid, $kind, $file, $parts);
    }

    /**
     * @return array{assets:bool,permissions:?string,publish:bool}
     */
    private static function modes(string $modes): array
    {
        return [
            'assets' => !str_contains($modes, '-noassets'),
            'permissions' => preg_match('/-perm([A-Za-z0-9_.]*)/', $modes, $m) === 1 ? $m[1] : null,
            'publish' => str_contains($modes, '-publish'),
        ];
    }

    /** The stamp of a name (`20261019-101500`) as Unix time. */
    private static function time(string $stamp): ?int
    {
        $date = \DateTimeImmutable::createFromFormat('Ymd-His', $stamp);

        return $date === false ? null : $date->getTimestamp();
    }
}
