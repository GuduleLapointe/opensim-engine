<?php

declare(strict_types=1);

namespace OpenSim\Data;

use OpenSim\Models\OpenSimModelInterface;

/**
 * What a list of the engine gives back: its items, and what a consumer needs to show them (the total before the pages, the pages,
 * the columns, the filters it can apply, the sort and the search that were applied). No database object leaks out of it.
 *
 *     $avatars = Avatar::all($config, ['search' => 'ada', 'sort' => 'lastSeen desc', 'perPage' => 20]);
 *     $avatars->first(); $avatars->count(); $avatars->total(); $avatars->array(); $avatars->json(); $avatars->csv();
 *
 * @implements \IteratorAggregate<int,mixed>
 */
final class Collection implements \Countable, \IteratorAggregate, \JsonSerializable
{
    /**
     * @param list<mixed> $items models (OpenSimModelInterface) or arrays
     * @param array{
     *     total?:int, page?:int, perPage?:int, sort?:string, search?:string,
     *     columns?:list<array{name:string,label:string,sortable?:bool}>,
     *     filters?:array<string,list<string>>, applied?:array<string,string>
     * } $meta
     */
    public function __construct(private array $items = [], private array $meta = [])
    {
    }

    /** The first item, null for an empty collection. */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    /** The last item, null for an empty collection. */
    public function last(): mixed
    {
        return $this->items === [] ? null : $this->items[array_key_last($this->items)];
    }

    /** How many items this collection holds (one page). */
    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** How many items there are before the pages, with the search and the filters applied. */
    public function total(): int
    {
        return $this->meta['total'] ?? count($this->items);
    }

    /** The page this collection is, from 1. */
    public function page(): int
    {
        return $this->meta['page'] ?? 1;
    }

    /** The size of a page, 0 for no pages. */
    public function perPage(): int
    {
        return $this->meta['perPage'] ?? 0;
    }

    /** How many pages there are. */
    public function pages(): int
    {
        $per = $this->perPage();

        return $per > 0 ? max(1, (int) ceil($this->total() / $per)) : 1;
    }

    /** The sort that was applied, "name asc". */
    public function sort(): string
    {
        return $this->meta['sort'] ?? '';
    }

    /** The search that was applied. */
    public function search(): string
    {
        return $this->meta['search'] ?? '';
    }

    /**
     * The columns the items have.
     *
     * @return list<array{name:string,label:string,sortable?:bool}>
     */
    public function columns(): array
    {
        return $this->meta['columns'] ?? [];
    }

    /**
     * The filters that can be applied and their values.
     *
     * @return array<string,list<string>>
     */
    public function filters(): array
    {
        return $this->meta['filters'] ?? [];
    }

    /**
     * The filters that were applied.
     *
     * @return array<string,string>
     */
    public function applied(): array
    {
        return $this->meta['applied'] ?? [];
    }

    /**
     * The items themselves.
     *
     * @return list<mixed>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * The items as arrays, with the standard names.
     *
     * @return list<array<string,mixed>>
     */
    public function array(): array
    {
        return array_map(
            static fn(mixed $item): array => $item instanceof OpenSimModelInterface ? $item->detail() : (array) $item,
            $this->items,
        );
    }

    public function json(int $flags = 0): string
    {
        return json_encode($this->array(), $flags | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** The items as CSV, the names of the columns first (the keys of the first item when the collection has no column). */
    public function csv(): string
    {
        $rows = $this->array();
        $names = $this->columns() !== [] ? array_column($this->columns(), 'name') : array_keys($rows[0] ?? []);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $names, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map(static fn(string $name): string => self::cell($row[$name] ?? null), $names), ',', '"', '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    /** @return \ArrayIterator<int,mixed> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /** @return list<array<string,mixed>> */
    public function jsonSerialize(): array
    {
        return $this->array();
    }

    private static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
