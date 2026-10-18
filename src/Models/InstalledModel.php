<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Data\Collection;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Interfaces\InstalledModelInterface;
use OpenSim\Runtime\Environment;

/**
 * What the models of the machine have in common (a grid, an instance): their records are folders and config files, and the
 * processes that run from them, in the environment of the machine. What a model does not do has its default: no owner, no flags, no
 * date, a write refused.
 */
abstract class InstalledModel implements InstalledModelInterface
{
    public function __construct(protected readonly Environment $environment) {}

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function uuid(): ?string
    {
        return null;
    }

    public function uri(): ?string
    {
        return null;
    }

    public function owner(): ?string
    {
        return null;
    }

    public function group(): ?string
    {
        return null;
    }

    public function flags(): int
    {
        return 0;
    }

    public function lastSeen(): ?\DateTimeImmutable
    {
        return null;
    }

    public function channels(): array
    {
        return [];
    }

    public function update(array $values): void
    {
        throw ChannelUnavailable::for('change ' . $this->name());
    }

    public function delete(): void
    {
        throw ChannelUnavailable::for('delete ' . $this->name());
    }

    public static function create(Environment $environment, array $values): static
    {
        throw ChannelUnavailable::for(
            'create a ' . strtolower((new \ReflectionClass(static::class))->getShortName()),
            'it is made by the setup',
        );
    }

    /**
     * A list as the contract says it: searched, filtered, sorted and paged, with what was applied.
     *
     * @param list<static> $items
     * @param array<string,mixed> $options search, sort ("name", "port desc"), filters, page, perPage (0: all)
     * @param \Closure(static):string $text what a search looks in
     * @param array<string,\Closure(static):(int|float|string)> $sorts the value of each column that can be sorted; the first is the default
     * @param array<string,array{0:\Closure(static,string):bool,1:list<string>}> $filters what each filter asks of a model, and the values it takes
     * @param list<array{name:string,label:string,sortable?:bool}> $columns
     */
    protected static function listing(
        array $items,
        array $options,
        \Closure $text,
        array $sorts,
        array $filters,
        array $columns,
    ): Collection {
        foreach (
            preg_split('/\s+/', trim((string) ($options['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []
            as $word
        ) {
            $items = array_filter($items, static fn($model): bool => stripos($text($model), $word) !== false);
        }
        $applied = [];
        foreach ((array) ($options['filters'] ?? []) as $name => $value) {
            if (isset($filters[$name]) && (string) $value !== '') {
                $ask = $filters[$name][0];
                $items = array_filter($items, static fn($model): bool => $ask($model, (string) $value));
                $applied[$name] = (string) $value;
            }
        }

        [$sortName, $direction] = array_pad(
            preg_split('/\s+/', trim((string) ($options['sort'] ?? ''))) ?: [],
            2,
            'asc',
        );
        if (!isset($sorts[$sortName])) {
            [$sortName, $direction] = [(string) array_key_first($sorts), 'asc'];
        }
        $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
        $value = $sorts[$sortName];
        $items = array_values($items);
        usort($items, static function ($a, $b) use ($value, $direction): int {
            [$x, $y] = [$value($a), $value($b)];
            $order = is_string($x) || is_string($y) ? strcasecmp((string) $x, (string) $y) : $x <=> $y;

            $order = $direction === 'desc' ? -$order : $order;

            return $order ?: strcmp((string) $a->id(), (string) $b->id());
        });

        $total = count($items);
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        if ($perPage > 0) {
            $items = array_slice($items, ($page - 1) * $perPage, $perPage);
        }

        return new Collection($items, [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'sort' => "$sortName $direction",
            'search' => trim((string) ($options['search'] ?? '')),
            'columns' => $columns,
            'filters' => array_map(static fn(array $filter): array => $filter[1], $filters),
            'applied' => $applied,
        ]);
    }
}
