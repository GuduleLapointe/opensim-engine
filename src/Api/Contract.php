<?php

declare(strict_types=1);

namespace OpenSim\Api;

/**
 * The contract of the resources of Api, as an OpenAPI 3.1 document. The tests check it against the models: an attribute a model
 * gives and the contract does not know is a failure, and so is the other way round.
 */
final class Contract
{
    /**
     * The attributes of each resource and their types (JSON Schema); `?` after the type is for a value that may be null.
     */
    public const ATTRIBUTES = [
        'grids' => [
            'nick' => 'string',
            'name' => 'string',
            'slug' => 'string',
            'sources' => 'array',
            'directory' => 'string?',
            'remote' => 'boolean',
            'standalone' => 'boolean',
            'planned' => 'boolean',
            'enabled' => 'boolean?',
            'hypergrid' => 'boolean',
            'baseHostname' => 'string',
            'publicPort' => 'integer',
            'privatePort' => 'integer',
            'center' => 'string?',
            'regionSpacing' => 'integer',
            'preset' => 'string?',
            'coreDirectory' => 'string?',
            'robustIni' => 'string?',
            'connectors' => 'array',
            'regionFlags' => 'array',
            'roles' => 'array',
            'missingRoles' => 'array',
            'databaseHost' => 'string',
            'databaseName' => 'string',
            'databaseUser' => 'string',
            'webUrl' => 'string?',
            'helpers' => 'boolean',
            'simulators' => 'array?',
            'announcement' => 'object?',
        ],
        'simulators' => [
            'uri' => 'string',
            'name' => 'string',
            'slug' => 'string',
            'grid' => 'string?',
            'ini' => 'string',
            'link' => 'string',
            'enabled' => 'boolean',
            'running' => 'boolean',
            'httpPort' => 'integer',
            'consolePort' => 'integer',
            'console' => 'string',
            'databaseHost' => 'string?',
            'databaseName' => 'string?',
            'databaseUser' => 'string?',
            'estateDatabaseName' => 'string?',
            'estateName' => 'string?',
            'estateOwner' => 'string?',
            'regionsDirectory' => 'string?',
            'core' => 'string?',
        ],
        'services' => [
            'key' => 'string',
            'name' => 'string',
            'slug' => 'string',
            'grid' => 'string',
            'simulator' => 'string?',
            'uri' => 'string?',
            'port' => 'string',
            'provider' => 'string?',
            'served' => 'boolean',
            'external' => 'boolean',
            'connectors' => 'array',
            'section' => 'string?',
            'setting' => 'string?',
            'database' => 'string?',
        ],
    ];

    /** The filters of each list and the values they take. */
    private const FILTERS = [
        'grids' => ['remote' => ['yes', 'no'], 'enabled' => ['yes', 'no'], 'hypergrid' => ['yes', 'no']],
        'simulators' => [],
        'services' => ['served' => ['yes', 'no'], 'port' => ['private', 'public']],
    ];

    /** @return array<string,mixed> */
    public static function document(string $version = '1'): array
    {
        $paths = [];
        $schemas = [
            'Problem' => [
                'type' => 'object',
                'description' => 'RFC 9457 problem details',
                'required' => ['title', 'status'],
                'properties' => [
                    'type' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'detail' => ['type' => 'string'],
                    'instance' => ['type' => 'string'],
                ],
            ],
        ];
        foreach (array_keys(Api::RESOURCES) as $type) {
            $schemas[ucfirst($type)] = self::schema($type);
            $paths["/$type"] = [
                'get' => [
                    'summary' => "The $type, searched, filtered, sorted and paged",
                    'parameters' => self::parameters($type),
                    'responses' => [
                        '200' => [
                            'description' => "A page of the $type",
                            'content' => [
                                Response::JSON_API => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'data' => [
                                                'type' => 'array',
                                                'items' => ['$ref' => '#/components/schemas/' . ucfirst($type)],
                                            ],
                                            'meta' => ['type' => 'object'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '503' => self::problem('What the list is read from is not available'),
                    ],
                ],
            ];
            $paths["/$type/{id}"] = [
                'get' => [
                    'summary' => 'One of the ' . $type . ' by its id',
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => [
                            'description' => 'The resource',
                            'content' => [
                                Response::JSON_API => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'data' => ['$ref' => '#/components/schemas/' . ucfirst($type)],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        '404' => self::problem('There is none with this id'),
                    ],
                ],
            ];
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'OpenSim engine',
                'version' => $version,
                'description' => 'The grids, the simulators and the services of an install',
            ],
            'paths' => $paths,
            'components' => ['schemas' => $schemas],
        ];
    }

    /** @return array<string,mixed> the resource as JSON:API shows it: type, id, attributes */
    private static function schema(string $type): array
    {
        $properties = [];
        foreach (self::ATTRIBUTES[$type] as $name => $kind) {
            $nullable = str_ends_with($kind, '?');
            $json = rtrim($kind, '?');
            $properties[$name] = ['type' => $nullable ? [$json, 'null'] : $json];
        }

        return [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['const' => $type],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => $properties],
                'links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string']]],
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private static function parameters(string $type): array
    {
        $parameters = [
            ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string']],
            [
                'name' => 'sort',
                'in' => 'query',
                'description' => 'A column, `-` before it for the descending order',
                'schema' => ['type' => 'string'],
            ],
            ['name' => 'page[number]', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['name' => 'page[size]', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 0]],
        ];
        foreach (self::FILTERS[$type] as $name => $values) {
            $parameters[] = [
                'name' => "filter[$name]",
                'in' => 'query',
                'schema' => ['type' => 'string', 'enum' => $values],
            ];
        }

        return $parameters;
    }

    /** @return array<string,mixed> */
    private static function problem(string $description): array
    {
        return [
            'description' => $description,
            'content' => [Problem::CONTENT_TYPE => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
        ];
    }
}
