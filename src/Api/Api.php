<?php

declare(strict_types=1);

namespace OpenSim\Api;

use OpenSim\Data\Collection;
use OpenSim\Interfaces\InstalledModelInterface;
use OpenSim\Models\Grid;
use OpenSim\Models\Service;
use OpenSim\Models\Simulator;
use OpenSim\Runtime\Environment;

/**
 * What the machine offers as resources, listed and read the way JSON:API says, the errors told as problems (RFC 9457). The use cases
 * are the models': this layer only names the resources, reads the query and writes the documents, so that the command line, the HTTP
 * API and the PHP library show the same.
 *
 *     $api = new Api($environment);
 *     $api->list('services', ['filter' => ['served' => 'yes'], 'sort' => '-name', 'page' => ['number' => 2, 'size' => 20]]);
 *     $api->get('grids', 'vonda');
 *
 * The contract of these resources is Contract::document(), which the tests check against the models.
 */
final class Api
{
    /** The resources, by the name they have in an address. */
    public const RESOURCES = ['grids' => Grid::class, 'simulators' => Simulator::class, 'services' => Service::class];

    public function __construct(private readonly Environment $environment) {}

    /**
     * A page of the resources of a type.
     *
     * @param array<string,mixed> $query `search`, `sort` ("-name" is name descending), `filter[<name>]`, `page[number]`, `page[size]`
     */
    public function list(string $type, array $query = []): Response
    {
        $class = self::RESOURCES[$type] ?? null;
        if ($class === null) {
            return Response::problem(Problem::notFound("kind of resource called '$type'", "/$type"));
        }
        try {
            /** @var Collection $collection */
            $collection = $class::all($this->environment, self::options($query));
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, "/$type"));
        }

        return Response::document([
            'data' => array_map(
                fn(InstalledModelInterface $model): array => self::resource($type, $model),
                $collection->items(),
            ),
            'meta' => [
                'total' => $collection->total(),
                'page' => $collection->page(),
                'perPage' => $collection->perPage(),
                'sort' => $collection->sort(),
                'search' => $collection->search(),
                'filters' => $collection->applied(),
            ],
            'links' => ['self' => "/$type"],
        ]);
    }

    /** One resource by its id. */
    public function get(string $type, string $id): Response
    {
        $class = self::RESOURCES[$type] ?? null;
        $instance = "/$type/" . rawurlencode($id);
        if ($class === null) {
            return Response::problem(Problem::notFound("kind of resource called '$type'", $instance));
        }
        try {
            $model = $class::find($this->environment, $id);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return $model === null
            ? Response::problem(Problem::notFound(rtrim($type, 's') . " called '$id'", $instance))
            : Response::document(['data' => self::resource($type, $model), 'links' => ['self' => $instance]]);
    }

    /**
     * The options of a list from a query: the sort of JSON:API (`-name`) becomes the one of the models (`name desc`).
     *
     * @param array<string,mixed> $query
     * @return array{search?:string,sort?:string,filters?:array<string,string>,page?:int,perPage?:int}
     */
    public static function options(array $query): array
    {
        $options = [];
        isset($query['search']) && ($options['search'] = (string) $query['search']);
        if (isset($query['sort']) && $query['sort'] !== '') {
            $sort = (string) $query['sort'];
            $options['sort'] = str_starts_with($sort, '-') ? substr($sort, 1) . ' desc' : $sort;
        }
        isset($query['filter']) &&
            is_array($query['filter']) &&
            ($options['filters'] = array_map('strval', $query['filter']));
        isset($query['page']['number']) && ($options['page'] = (int) $query['page']['number']);
        isset($query['page']['size']) && ($options['perPage'] = (int) $query['page']['size']);

        return $options;
    }

    /** @return array{type:string,id:string,attributes:array<string,mixed>,links:array{self:string}} */
    private static function resource(string $type, InstalledModelInterface $model): array
    {
        $id = (string) $model->id();
        $attributes = $model->detail();
        unset($attributes['id']);

        return [
            'type' => $type,
            'id' => $id,
            'attributes' => $attributes,
            'links' => ['self' => "/$type/" . rawurlencode($id)],
        ];
    }
}
