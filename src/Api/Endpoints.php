<?php

declare(strict_types=1);

namespace OpenSim\Api;

/**
 * The endpoints of a grid that are not resources: what OpenSimulator and the viewers call in a format of their own (the search, the
 * currency, the land tools, the offline messages, the registration of the simulators), the pages of the helpers, and `get_grid_info`
 * of Robust. They are in the contract as endpoints of their own, with the format they have; their address is the grid's to choose, the
 * contract gives the one the engine serves them at.
 *
 * @see Formats
 */
final class Endpoints
{
    /**
     * @return array{paths:array<string,mixed>,tags:list<array{name:string,description:string}>,groups:array<string,list<string>>}
     */
    public static function document(): array
    {
        $paths = [];
        $tags = [
            [
                'name' => 'Search',
                'description' =>
                    'The search of the grid, made by the helpers: the simulators register to it and tell it their regions (a snapshot the helpers read), the viewers ask it for places, popular places, land for sale, events and classifieds. The format is the one of the module OpenSimSearch of the simulators; the address is the grid\'s to choose (`SearchURL` of `[Search]` and `DATA_SRV_*` of `[DataSnapshot]` in OpenSim.ini). A search can be shared by several grids: `gk`, the gatekeeper of the grid that asks, tells for which the search is made (the viewers do not send it).',
            ],
            [
                'name' => 'Economy',
                'description' =>
                    'The currency and the land tools of the grid, as the viewers and the money modules call them: XML-RPC at `currency.php` and `landtool.php` under the economy address the grid gives its viewers (`economy` of `[GridInfoService]`). Nothing is served without the key of the grid (`MoneyScriptAccessKey` of `[MoneyServer]`): a quote is tied to its purchase with it and to the address of who asked. An avatar proves who they are with their secure session, a region with its secret.',
            ],
            [
                'name' => 'Offline messages',
                'description' =>
                    'The messages for the avatars who are not there, with the module V1 of the simulators (`OfflineMessageModule = OfflineMessageModule`, `OfflineMessageURL` of `[Messaging]`), which posts them here in XML. The messages are kept in the table of the offline messages of Robust, the one its module V2 uses; an avatar who asked for it in their viewer also gets them by email. The other choice is the module V2 and the service of Robust, which needs none of this: with the helpers, the messages are mailed on request and none is lost; with Robust, nothing more is run, no mail is sent and 25 messages are kept per avatar.',
            ],
            [
                'name' => 'Health',
                'description' => 'Whether the grid and its web services answer as they should.',
            ],
            [
                'name' => 'Message of the day',
                'description' => 'The text the viewers show at login.',
            ],
            [
                'name' => 'Text images',
                'description' => 'Pictures of a text, for the signs and the boards in world.',
            ],
            [
                'name' => 'Pages',
                'description' =>
                    'The pages the helpers show to the people, in HTML, at the addresses the grid gives (`GridInfoService` and `LoginService` in Robust.ini). They are made from the resources of this API: the guide shows `/grids/{grid}/destinations`, the profile an avatar.',
            ],
            [
                'name' => 'Grid info',
                'description' => 'What Robust itself serves, which the engine reads.',
            ],
        ];

        $paths['/grids/{grid}/health']['get'] = [
            'operationId' => 'getHealth',
            'tags' => ['Health'],
            'summary' => 'Health of the grid',
            'description' =>
                'The addresses the config of the grid gives (the services of Robust, the web services, the pages), what the grid says of itself at `get_grid_info`, and the ones that are expected and not given, asked now: a service that must refuse a call without data refuses it, and answers one that makes sense. The PHP extensions the engine needs and the files it reads come with them. `level` is `error` when something does not answer as it should, `ok` when all that is asked does, `none` when there is nothing to ask. The groups are the sections of the files of the grid; each row has the address, the status (the HTTP code, or the error), the time in milliseconds and its own level.',
            'parameters' => [Contract::gridParameter()],
            'responses' => [
                '200' => [
                    'description' => 'The health of the grid',
                    'content' => [
                        Response::JSON_API => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'type' => ['const' => 'health'],
                                            'id' => ['type' => 'string'],
                                            'attributes' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'level' => ['type' => 'string', 'enum' => ['ok', 'error', 'none']],
                                                    'groups' => [
                                                        'type' => 'array',
                                                        'items' => [
                                                            'type' => 'object',
                                                            'properties' => [
                                                                'file' => [
                                                                    'type' => 'string',
                                                                    'description' => 'The file the section is in',
                                                                ],
                                                                'title' => [
                                                                    'type' => 'string',
                                                                    'description' => 'The section',
                                                                ],
                                                                'rows' => [
                                                                    'type' => 'array',
                                                                    'items' => ['type' => 'object'],
                                                                ],
                                                            ],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '404' => Contract::problem('The grid is not known here'),
            ],
        ];
        $paths['/grids/{grid}/search/register']['get'] = [
            'operationId' => 'registerSimulator',
            'tags' => ['Search'],
            'summary' => 'Register a simulator',
            'description' =>
                'What the module of search of a simulator calls (`DATA_SRV_<grid>` of `[DataSnapshot]` is the address): a GET with the host and the port **of the simulator**, not those of the grid. The answer is nothing: the simulator waits for it to go on starting. The helpers read its snapshot once the answer is sent, after a pause for its regions to load, and again soon after; one that does not answer is read less and less often, then forgotten. `offline` forgets it and what it told.',
            'parameters' => [Contract::gridParameter(), ...self::query(Formats::REGISTER)],
            'responses' => [
                '200' => ['description' => 'Registered (or unregistered); nothing is said'],
                '400' => self::text('The host, the port or the service is missing or wrong'),
                '404' => self::text('The grid is not known here'),
                '503' => self::text('The grid has no search database, or it cannot be reached'),
            ],
        ];

        $paths['/grids/{grid}/search/query']['post'] = self::xmlRpc(
            'searchQuery',
            'Search',
            'Search questions',
            'The questions of the viewers and of the simulators to the search. XML-RPC, as the module OpenSimSearch speaks it: the body is a method call with one struct, the answer a struct with `success`, `errorMessage` and the rows in `data` (nothing in `data` when there is no result or it failed). The grid has the status of a failure only when the request is not understood or the search is not there.',
            Formats::SEARCH,
            [Contract::gridParameter(), self::gatekeeper()],
            true,
        );
        $paths['/grids/{grid}/currency.php']['post'] = self::xmlRpc(
            'currency',
            'Economy',
            'Currency',
            'Quotes, purchases, balances and transfers. XML-RPC at `currency.php` of the economy address of the grid. The viewer asks the quote and buys; the simulators ask the balance of the avatars who are on them, move the money of a purchase, and claim the avatars that arrive. The answer is a struct with `success`, and the reason (`errorMessage`, `errorURI`) when it is false.',
            Formats::CURRENCY,
            [Contract::gridParameter()],
            false,
        );
        $paths['/grids/{grid}/landtool.php']['post'] = self::xmlRpc(
            'landTool',
            'Economy',
            'Land tools',
            'The purchase of land: what it costs and the currency it needs. XML-RPC at `landtool.php` of the economy address of the grid. The land itself is paid afterwards, by the region.',
            Formats::LAND,
            [Contract::gridParameter()],
            false,
        );

        foreach (Formats::OFFLINE as $method => $format) {
            $paths["/grids/{grid}/offline-messages/$method"]['post'] = [
                'operationId' => "offline$method",
                'tags' => ['Offline messages'],
                'summary' => $method === 'SaveMessage' ? 'Save a message' : 'Retrieve messages',
                'description' => "{$format['summary']}. What the module V1 of the simulators posts to `<OfflineMessageURL>/$method/` (the last slash is theirs, it is accepted with or without).\n\n**Request**: {$format['request']}.\n\n**Answer**: {$format['answer']}.",
                'parameters' => [Contract::gridParameter()],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'text/xml' => [
                            'schema' => ['type' => 'string', 'description' => 'XML'],
                            'examples' => ['example' => ['value' => $format['example']]],
                        ],
                    ],
                ],
                'responses' => [
                    '200' => [
                        'description' => $format['answer'],
                        'content' => ['text/xml' => ['schema' => ['type' => 'string']]],
                    ],
                    '400' => self::xmlProblem('The body is empty, or is not XML'),
                    '404' => self::xmlProblem('The grid is not known here, or the method is not one of the module'),
                    '503' => self::xmlProblem(
                        'The grid has no database for the offline messages, or it cannot be reached',
                    ),
                ],
            ];
        }

        $page = static fn(string $id, string $summary, string $description, array $parameters = []): array => [
            'operationId' => $id,
            'tags' => ['Pages'],
            'summary' => $summary,
            'description' => $description,
            'parameters' => $parameters,
            'responses' => [
                '200' => [
                    'description' => 'The page',
                    'content' => ['text/html' => ['schema' => ['type' => 'string']]],
                ],
                '404' => ['description' => 'The grid does not serve this page'],
            ],
        ];
        $paths['/search']['get'] = $page(
            'pageSearch',
            'Web search',
            'The page the viewers open for the search. The address is `search` of `[GridInfoService]` and `SearchURL` of `[LoginService]`, which the setup keeps equal.',
        );
        $paths['/welcome']['get'] = $page(
            'pageWelcome',
            'Welcome',
            'The page the viewers show at login. The address is `welcome` of `[GridInfoService]`.',
        );
        $paths['/guide']['get'] = $page(
            'pageGuide',
            'Destination guide',
            'The page the viewers open for the destinations (`DestinationGuide` of `[LoginService]`), made from `/grids/{grid}/destinations`.',
        );
        $paths['/profile/{name}']['get'] = $page(
            'pageProfile',
            'Profile',
            'The page the viewers open for a profile (`web_profile_url` of `[GridInfoService]`, `[AGENT_NAME]` standing for the name).',
            [
                [
                    'name' => 'name',
                    'in' => 'path',
                    'required' => true,
                    'description' => 'The name of the avatar',
                    'schema' => ['type' => 'string'],
                ],
            ],
        );
        $paths['/health']['get'] = $page(
            'pageHealth',
            'Health page',
            'A management page: the same checks as `/grids/{grid}/health`, for a person to read. It is not a page of the viewers, and not public.',
        );
        $paths['/grid_status']['get'] = $page(
            'pageGridStatus',
            'Grid status',
            'A light page, in HTML or plain text, from which the viewer takes one line of global status by keyword (ONLINE green, OFFLINE red, DEGRADED or MAINTENANCE orange). The address is `GridStatus` of `[GridInfoService]`.',
        );
        $paths['/grid_status.rss']['get'] = [
            'operationId' => 'feedGridStatus',
            'tags' => ['Pages'],
            'summary' => 'Grid status feed',
            'description' =>
                'The RSS feed the viewers read for the news of the grid (`GridStatusRSS` of `[GridInfoService]`).',
            'responses' => [
                '200' => [
                    'description' => 'The feed',
                    'content' => ['application/rss+xml' => ['schema' => ['type' => 'string']]],
                ],
                '404' => ['description' => 'The grid has no feed'],
            ],
        ];
        $paths['/get_grid_info']['get'] = [
            'operationId' => 'getGridInfo',
            'tags' => ['Grid info'],
            'summary' => 'Grid info',
            'description' =>
                'Served by Robust at its public port, not by the engine, which reads it (`Grid::gridInfo()`). The elements are the keys of `[GridInfoService]` of Robust.ini, **every one**, the free ones included: `gridname`, `gridnick`, `login` (the address of the grid, which is the truth about it whatever address it was reached at), `economy`, `search`, `welcome`... What a grid says is kept for a day when it is verified.',
            'servers' => [
                [
                    'url' => '{login}',
                    'description' => 'The login address of the grid',
                    'variables' => ['login' => ['default' => 'http://grid.example.org:8002']],
                ],
            ],
            'responses' => [
                '404' => ['description' => 'The address is not the login of a grid'],
                '200' => [
                    'description' => 'The elements of `[GridInfoService]`',
                    'content' => [
                        'text/xml' => [
                            'schema' => ['type' => 'string'],
                            'examples' => [
                                'example' => [
                                    'value' =>
                                        "<?xml version=\"1.0\"?>\n<gridinfo><gridname>Demo World</gridname><gridnick>demo</gridnick><login>http://grid.example.org:8002/</login><economy>https://web.example.org/helpers</economy><search>https://web.example.org/search</search></gridinfo>",
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $groups = [
            'Grid' => ['Message of the day', 'Search', 'Economy', 'Offline messages', 'Health'],
            'Application' => ['Text images'],
            'Helpers' => ['Pages'],
            'Robust' => ['Grid info'],
        ];

        return ['paths' => $paths, 'tags' => $tags, 'groups' => $groups];
    }

    /**
     * What `/grids/{grid}/destinations` takes and gives besides the resources: the lines the boards of the scripts read.
     *
     * @param array<string,mixed> $operation
     * @return array<string,mixed>
     */
    public static function destinations(array $operation): array
    {
        $operation['description'] =
            'The list of the guide. `GuideSource` of `[Helpers]` (a file or an address of "Name|Region URL" lines, a name alone starting a category) is the list; a grid that gives none has the places its search knows, ten popular places and ten places shown in search, in two sections. With `format=list` (or `Accept: text/plain`) it is the lines themselves, as the boards of the scripts read them.';
        $operation['parameters'][] = [
            'name' => 'format',
            'in' => 'query',
            'required' => false,
            'description' => '`list` gives the lines the boards of the scripts read, in plain text',
            'schema' => ['type' => 'string', 'enum' => ['list']],
        ];
        $operation['responses']['200']['content']['text/plain'] = [
            'schema' => ['type' => 'string'],
            'examples' => [
                'list' => [
                    'value' =>
                        "Popular places\nRose garden|grid.example.org:8002/Welcome/128/128/25\nPlaces in the search\nLibrary|grid.example.org:8002/Welcome/64/64/22\n",
                ],
            ],
        ];

        return $operation;
    }

    /**
     * An XML-RPC endpoint, its methods described.
     *
     * @param array<string,array{summary:string,request:array<string,array{0:string,1:string,2:string}>,answer:array<string,array{0:string,1:string,2:string}>}> $methods
     * @param list<array<string,mixed>> $parameters
     * @return array<string,mixed>
     */
    private static function xmlRpc(
        string $id,
        string $tag,
        string $summary,
        string $description,
        array $methods,
        array $parameters,
        bool $rows,
    ): array {
        $text = "$description\n";
        $requests = [];
        $answers = [];
        foreach ($methods as $name => $method) {
            $text .=
                "\n### `$name`\n\n{$method['summary']}.\n\n**Request** (the struct)\n\n" .
                self::table($method['request']);
            $text .=
                $method['answer'] === []
                    ? "\n\n**Answer**: `success`, with the reason in `errorMessage` when it failed.\n"
                    : "\n\n**Answer**" .
                        ($rows ? ' (each row of `data`)' : '') .
                        "\n\n" .
                        self::table($method['answer']) .
                        "\n";
            $requests[$name] = ['summary' => $name, 'value' => Formats::requestExample($name, $method['request'])];
            $answers[$name] = ['summary' => $name, 'value' => Formats::answerExample($method['answer'], $rows)];
        }

        return [
            'operationId' => $id,
            'tags' => [$tag],
            'summary' => $summary,
            'description' => $text,
            'parameters' => $parameters,
            'requestBody' => [
                'required' => true,
                'content' => [
                    'text/xml' => [
                        'schema' => ['type' => 'string', 'description' => 'XML-RPC method call'],
                        'examples' => $requests,
                    ],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The answer of the method',
                    'content' => ['text/xml' => ['schema' => ['type' => 'string'], 'examples' => $answers]],
                ],
                '400' => self::xmlProblem('The body is not an XML-RPC request'),
                '403' => self::xmlProblem(
                    'The caller does not hold the secure session of the avatar (avatar_publish_update)',
                ),
                '404' => self::xmlProblem('The grid is not known here'),
                '503' => self::xmlProblem('The grid has no database for it, or no key for its currency'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private static function gatekeeper(): array
    {
        return [
            'name' => 'gk',
            'in' => 'query',
            'required' => false,
            'description' =>
                'The gatekeeper of the grid the search is made for (`grid.example.org:8002`), when the search is shared: only its places are answered',
            'schema' => ['type' => 'string'],
        ];
    }

    /**
     * @param array<string,array{0:string,1:string,2:string}> $fields
     * @return list<array<string,mixed>>
     */
    private static function query(array $fields): array
    {
        $parameters = [];
        foreach ($fields as $name => [$type, $description, $example]) {
            $parameters[] = [
                'name' => $name,
                'in' => 'query',
                'required' => true,
                'description' => $description,
                'schema' =>
                    ['type' => $type] +
                    ($example !== '' ? ['example' => $type === 'integer' ? (int) $example : $example] : []),
            ];
        }

        return $parameters;
    }

    /**
     * @param array<string,array{0:string,1:string,2:string}> $fields
     */
    private static function table(array $fields): string
    {
        $rows = ['| Field | Type | |', '| --- | --- | --- |'];
        foreach ($fields as $name => [$type, $description]) {
            $rows[] = "| `$name` | $type | " . str_replace('|', '\\|', $description) . ' |';
        }

        return implode("\n", $rows);
    }

    /** @return array<string,mixed> */
    private static function text(string $description): array
    {
        return ['description' => $description, 'content' => ['text/plain' => ['schema' => ['type' => 'string']]]];
    }

    /** @return array<string,mixed> */
    private static function xmlProblem(string $description): array
    {
        return ['description' => $description, 'content' => ['text/xml' => ['schema' => ['type' => 'string']]]];
    }
}
