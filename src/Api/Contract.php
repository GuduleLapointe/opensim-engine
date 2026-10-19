<?php

declare(strict_types=1);

namespace OpenSim\Api;

use OpenSim\Models\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * The contract of the resources of Api, as an OpenAPI 3.1 document: what is asked, what comes back, what is written. It is also the
 * reference of the API: the descriptions here are the text of the documentation (`composer docs` makes the pages), so this is where
 * to write them. The tests check it against the models: an attribute a model gives and the contract does not know is a failure, and
 * so is the other way round.
 */
final class Contract
{
    /**
     * The resources: where they are, what they are for, their attributes (`name => [type, description]`, `?` after the type for a value
     * that may be null), the filters of their list and the values these take, and what can be written.
     *
     * `scope` is `grid` for the resources of a grid (`/grids/{grid}/<type>`), `machine` for the ones of the machine, `application`
     * for what the application that uses the engine provides (its tasks, the forms of its setup).
     */
    /** The resources whose list is whole: not searched, sorted or paged. */
    private const UNPAGED = ['tasks', 'forms', 'destinations', 'fonts'];

    public const RESOURCES = [
        'grids' => [
            'scope' => 'machine',
            'summary' => 'The grids of this machine',
            'description' =>
                'A grid run from this machine, only described here (a grid joined on another machine), or a standalone (one simulator that serves it all).',
            'attributes' => [
                'nick' => ['string', 'What names its folder, its databases and its instances (snake_case)'],
                'name' => ['string', 'The name shown'],
                'slug' => ['string', 'The name made for an address (kebab-case)'],
                'sources' => ['array', 'Where it is known from: folder, plan, described, announced'],
                'directory' => ['string?', 'Its folder'],
                'remote' => ['boolean', 'Its Robust is on another machine'],
                'standalone' => ['boolean', 'One simulator serves the grid itself, no Robust runs'],
                'planned' => ['boolean', 'Not made yet'],
                'enabled' => [
                    'boolean?',
                    'Its Robust (or its simulator, for a standalone) is enabled: null when it is not on this machine',
                ],
                'hypergrid' => ['boolean', 'Open to the other grids'],
                'baseHostname' => ['string', 'The host its viewers log in to'],
                'publicPort' => ['integer', 'The port of the viewers and of the other grids'],
                'privatePort' => ['integer', 'The port of its simulators'],
                'center' => ['string?', 'The center of its map (x,y)'],
                'regionSpacing' => ['integer', 'The free blocks its regions leave between them'],
                'preset' => ['string?', 'The preset of the grid it joins (OSGrid...)'],
                'coreDirectory' => ['string?', 'The OpenSimulator release it runs'],
                'robustIni' => ['string?', 'The config of its Robust'],
                'connectors' => ['array', 'The services its Robust serves (the keys of [ServiceList])'],
                'regionFlags' => ['array', 'The roles its Robust gives to regions'],
                'roles' => ['array', 'The roles a region of the grid can have'],
                'missingRoles' => ['array', 'The roles no region has yet'],
                'databaseHost' => ['string', 'The host of its database'],
                'databaseName' => ['string', 'The name of its database'],
                'databaseUser' => ['string', 'The user of its database'],
                'webUrl' => ['string?', 'The web site of its helpers'],
                'helpers' => ['boolean', 'Its helpers are installed'],
                'simulators' => ['array?', 'The names of its simulators'],
                'announcement' => ['object?', 'What the grid says of itself at get_grid_info'],
            ],
            'filters' => ['remote' => ['yes', 'no'], 'enabled' => ['yes', 'no'], 'hypergrid' => ['yes', 'no']],
            'writable' => [
                'enabled' => ['boolean', 'Enable or disable the grid (its Robust); a running grid is not stopped'],
            ],
        ],
        'simulators' => [
            'scope' => 'machine',
            'summary' => 'The simulators of this machine',
            'description' =>
                'A simulator is identified by its address (host:port); its slug has its grid (`vonda_welcome`).',
            'attributes' => [
                'uri' => ['string', 'Its address, host:port of its HTTP port: what identifies it'],
                'name' => ['string', 'The name shown'],
                'slug' => ['string', 'Its grid, an underscore, its own slug'],
                'grid' => ['string?', 'The nick of its grid'],
                'ini' => ['string', 'Its config'],
                'link' => ['string', 'The link that enables it'],
                'enabled' => ['boolean', 'Its link is there'],
                'running' => ['boolean', 'Its process is running'],
                'httpPort' => ['integer', 'Its HTTP port, the first of its block of ten'],
                'consolePort' => ['integer', 'The port of its console (x4 of the block)'],
                'console' => ['string', 'How its console is reached: rest or screen'],
                'databaseHost' => ['string?', 'The host of its database'],
                'databaseName' => ['string?', 'The name of its database'],
                'databaseUser' => ['string?', 'The user of its database'],
                'estateDatabaseName' => ['string?', 'The database of its estates, when it has its own'],
                'estateName' => ['string?', 'The name of its estate'],
                'estateOwner' => ['string?', 'The owner of its estate'],
                'regionsDirectory' => ['string?', 'The folder of its regions'],
                'core' => ['string?', 'The OpenSimulator release it runs'],
            ],
            'filters' => [],
            'writable' => [
                'enabled' => ['boolean', 'Enable or disable the simulator; a running simulator is not stopped'],
            ],
        ],
        'services' => [
            'scope' => 'machine',
            'summary' => 'The services of the grids of this machine',
            'description' =>
                "What the simulators and the users of a grid ask for: the assets, the profiles, the login, the search. A service is served by a Robust of the grid, by the simulator of a standalone, or by a web site (the helpers) at an address the config of the grid gives.\n\n`get-grid-info` is the /get_grid_info that Robust answers: the engine reads it, it does not provide one.\n\n`kind` says who fixes the format of a web service: `fixed` (OpenSimulator or the viewers: only the address is the grid's to choose, and the format is described in this contract as an endpoint of its own) or `standard` (the engine's own; `standard` is its address, `legacy` the script that answered it before). `endpoint` is where the engine serves it.",
            'attributes' => [
                'key' => ['string', 'What it is for: asset, inventory, profile, search...'],
                'name' => ['string', 'The name shown'],
                'slug' => ['string', 'Its grid, an underscore, its key'],
                'grid' => ['string', 'The nick of its grid'],
                'simulator' => [
                    'string?',
                    'The simulator that asks, when the service is shown as that simulator reaches it',
                ],
                'url' => ['string?', 'The link to the service, where it is reached'],
                'port' => [
                    'string',
                    'private (the simulators), public (the viewers and other grids) or web (an address on a web site)',
                ],
                'provider' => ['string?', 'The instance that serves it, null when none of this machine does'],
                'served' => ['boolean', 'A Robust of the grid, or the simulator of a standalone, serves it'],
                'external' => ['boolean', 'Declared for the simulators, served somewhere else'],
                'connectors' => ['array', 'The connectors of Robust that serve it'],
                'section' => ['string?', 'The section of the config of the simulators that gives its address'],
                'setting' => ['string?', 'The setting of the config that gives its address'],
                'database' => ['string?', 'The database it stores in'],
                'web' => ['boolean', 'An address on a web site, not a port of Robust'],
                'kind' => ['string?', 'fixed or standard, for a web service'],
                'legacy' => ['string?', 'The script that answered it before the standard'],
                'standard' => ['string?', 'Its address in the standard of the engine, when it has one'],
                'endpoint' => [
                    'string?',
                    'Where the engine serves it (the standard address, or the one of the fixed format), when it does',
                ],
                'source' => [
                    'string?',
                    'Where its address comes from: provider, simulator, live (the running grid), config, default (where the helpers answer)',
                ],
            ],
            'filters' => ['served' => ['yes', 'no'], 'port' => [Service::PRIVATE, Service::PUBLIC, Service::WEB]],
            'writable' => [],
        ],
        'archives' => [
            'scope' => 'machine',
            'summary' => 'The archives and the backups of the grids of this machine',
            'description' =>
                'The inventories (`iar`) and the regions (`oar`) the consoles of the simulators saved, in the data folder of their grid, and the backups of the setup (`backup`: the configuration, the data and the databases of a grid or of a simulator, `.tar.gz`). A file says what is in it by its name; they are read here, made by `opensim save iar|oar` and `opensim backup`.',
            'attributes' => [
                'name' => ['string', 'The name of the file'],
                'slug' => ['string', 'The grid, the kind and the name without its extension'],
                'grid' => ['string', 'The nick of its grid'],
                'kind' => ['string', 'iar, oar or backup'],
                'file' => ['string', 'Its path'],
                'size' => ['integer', 'Bytes'],
                'modified' => ['integer?', 'Unix time of the last change of the file'],
                'stamp' => ['integer?', 'Unix time the name says it was made'],
                'user' => ['string?', 'Whose inventory, for an iar'],
                'simulator' => ['string?', 'The simulator, for an oar or a backup of a simulator'],
                'region' => ['string?', 'The region, for an oar of one region'],
                'assets' => ['boolean?', 'With the assets (false when the name says noassets), for an iar or an oar'],
                'permissions' => ['string?', 'The permissions kept (`perm`), for an iar or an oar'],
                'publish' => ['boolean?', 'Saved to be published, for an oar'],
                'logs' => ['boolean?', 'With the logs, for a backup'],
                'archives' => ['boolean?', 'With the archives of the users, for a backup'],
            ],
            'filters' => ['kind' => ['iar', 'oar', 'backup']],
            'writable' => [],
        ],
        'avatars' => [
            'scope' => 'grid',
            'summary' => 'The avatars of a grid',
            'description' =>
                'Read from the databases of the grid, each in the one of its service. An avatar is made with its account, its password, its inventory and its default outfit. Its name does not change.',
            'attributes' => [
                'uuid' => ['string', 'Its UUID'],
                'name' => ['string', 'First Last'],
                'slug' => ['string', 'first-last'],
                'uri' => ['string?', 'first.last@host:port'],
                'firstName' => ['string', ''],
                'lastName' => ['string', ''],
                'grid' => ['string?', 'The grid of a visitor, null for an avatar of this grid'],
                'local' => ['boolean', 'An avatar of this grid'],
                'email' => ['string?', ''],
                'title' => ['string', 'Its title'],
                'created' => ['integer?', 'When the account was made (Unix time)'],
                'level' => ['integer', 'The user level, 200 and over are gods'],
                'isGod' => ['boolean', ''],
                'flags' => ['integer', 'The user flags'],
                'active' => ['boolean', 'The account can log in'],
                'online' => ['boolean', 'In the world now'],
                'lastSeen' => ['integer?', 'The last login or logout (Unix time)'],
                'homeRegion' => ['string?', 'The UUID of its home region'],
                'lastRegion' => ['string?', 'The UUID of the region it was in'],
                'technical' => ['boolean', 'An account of the services, not a person'],
                'profileImage' => ['string?', ''],
                'about' => ['string?', ''],
                'profileUrl' => ['string?', ''],
                'partner' => ['string?', 'The UUID of its partner'],
                'wants' => ['string?', 'The free text of the wants'],
                'wantsList' => ['array', 'What it wants: Build, Explore, Meet, Group, Buy, Sell, Be Hired, Hire'],
                'skills' => ['string?', 'The free text of the skills'],
                'skillsList' => [
                    'array',
                    'What it is good at: Textures, Architecture, Event Planning, Modeling, Scripting, Custom Characters',
                ],
                'languages' => ['string?', ''],
                'firstImage' => ['string?', ''],
                'firstLife' => ['string?', ''],
            ],
            'filters' => [
                'active' => ['yes', 'no'],
                'online' => ['yes', 'no'],
                'god' => ['yes', 'no'],
                'technical' => ['yes', 'no'],
            ],
            'create' => [
                'name' => ['string', 'First Last (or firstName and lastName)'],
                'email' => ['string', ''],
                'password' => ['string', 'The password (or passwordHash)'],
                'passwordHash' => ['object', 'hash and salt of a password that is moved'],
                'uuid' => ['string', 'The UUID of an account that is moved'],
                'home' => ['string', 'The UUID of the home region'],
            ],
            'writable' => [
                'email' => ['string', ''],
                'title' => ['string', ''],
                'level' => ['integer', ''],
                'active' => ['boolean', ''],
                'password' => ['string', 'A new password'],
                'home' => ['string', 'The UUID of the home region'],
                'about' => ['string', 'Of the profile the avatar has'],
                'profileUrl' => ['string', ''],
                'wants' => ['string', ''],
                'skills' => ['string', ''],
                'languages' => ['string', ''],
                'firstLife' => ['string', ''],
            ],
            'deletable' => true,
        ],
        'regions' => [
            'scope' => 'grid',
            'summary' => 'The regions of a grid',
            'description' => 'The regions the grid knows (its table of regions) and the ones its simulators describe.',
            'attributes' => [
                'uuid' => ['string', ''],
                'name' => ['string', ''],
                'slug' => ['string', ''],
                'uri' => ['string?', ''],
                'location' => ['string', 'x,y on the map, in regions'],
                'x' => ['integer', ''],
                'y' => ['integer', ''],
                'size' => ['string', 'width x height in meters'],
                'width' => ['integer', ''],
                'height' => ['integer', ''],
                'ip' => ['string?', ''],
                'port' => ['integer?', ''],
                'serverUri' => ['string?', ''],
                'handle' => ['integer?', 'The handle of its location'],
                'owner' => ['string?', 'The UUID of its owner'],
                'ownerName' => ['string?', ''],
                'flags' => ['integer', 'The flags of the grid'],
                'roles' => ['array', 'The roles its flags give: DefaultRegion, FallbackRegion...'],
                'flagLabels' => ['array', ''],
                'isDefault' => ['boolean', ''],
                'isFallback' => ['boolean', ''],
                'online' => ['boolean', ''],
                'hyperlink' => ['boolean', 'A link to a region of another grid'],
                'lastSeen' => ['integer?', ''],
                'access' => ['integer?', 'The access level of the region'],
                'mapImage' => ['string?', ''],
                'sources' => ['array', 'The grid, the config of a simulator, the search'],
                'simulator' => ['string?', 'The simulator that has it'],
                'file' => ['string?', 'Its file in the simulator'],
                'internalPort' => ['integer?', ''],
                'enabled' => [
                    'boolean?',
                    'Its file ends in .ini; null for a region of the grid with no file of this machine',
                ],
            ],
            'filters' => ['online' => ['yes', 'no'], 'hyperlink' => ['yes', 'no']],
            'writable' => [
                'enabled' => ['boolean', 'Enable or disable the region (its file); the simulator has to restart'],
            ],
        ],
        'parcels' => [
            'scope' => 'grid',
            'summary' => 'The parcels of a grid',
            'description' => 'The land of every simulator of the grid, read in the database of each.',
            'attributes' => [
                'uuid' => ['string', ''],
                'name' => ['string', ''],
                'slug' => ['string', ''],
                'uri' => ['string?', ''],
                'description' => ['string?', ''],
                'region' => ['string', 'The UUID of its region'],
                'regionName' => ['string?', ''],
                'localId' => ['integer?', ''],
                'owner' => ['string?', ''],
                'ownerName' => ['string?', ''],
                'group' => ['string?', ''],
                'isGroupOwned' => ['boolean', ''],
                'area' => ['integer', 'In square meters'],
                'category' => ['integer?', ''],
                'claimed' => ['integer?', ''],
                'price' => ['integer', ''],
                'forSale' => ['boolean', ''],
                'flags' => ['integer', ''],
                'shownInSearch' => ['boolean', ''],
                'dwell' => ['number?', ''],
                'landing' => ['array?', 'The landing point: x, y, z'],
                'snapshot' => ['string?', ''],
                'music' => ['string?', ''],
                'media' => ['string?', ''],
            ],
            'filters' => ['shown' => ['yes', 'no']],
            'writable' => [],
        ],
        'estates' => [
            'scope' => 'grid',
            'summary' => 'The estates of a grid',
            'description' => 'The estates of the grid when its Robust serves them, else the ones of each simulator.',
            'attributes' => [
                'number' => ['integer', 'Its number in its database'],
                'simulator' => ['string?', 'The simulator that has it, null for an estate of the grid'],
                'name' => ['string', ''],
                'slug' => ['string', ''],
                'owner' => ['string?', ''],
                'ownerName' => ['string?', ''],
                'parent' => ['integer?', ''],
                'public' => ['boolean', ''],
                'denyAnonymous' => ['boolean', ''],
                'denyIdentified' => ['boolean', ''],
                'denyTransacted' => ['boolean', ''],
                'denyMinors' => ['boolean', ''],
                'allowVoice' => ['boolean', ''],
                'allowDirectTeleport' => ['boolean', ''],
                'allowEnvironmentOverride' => ['boolean', ''],
                'resetHomeOnTeleport' => ['boolean', ''],
                'skipScripts' => ['boolean', ''],
                'taxFree' => ['boolean', ''],
                'pricePerMeter' => ['integer', ''],
                'billableFactor' => ['number', ''],
                'fixedSun' => ['boolean', ''],
                'useGlobalTime' => ['boolean', ''],
                'sunPosition' => ['number', ''],
                'abuseEmail' => ['string?', ''],
                'abuseEmailToOwner' => ['boolean', ''],
                'regions' => ['array', 'The UUIDs of its regions'],
                'managers' => ['array', ''],
                'users' => ['array', ''],
                'groups' => ['array', ''],
                'bans' => ['array', ''],
            ],
            'filters' => [],
            'writable' => [],
        ],
        'assets' => [
            'scope' => 'grid',
            'summary' => 'The assets of a grid',
            'description' =>
                'What the grid keeps in its asset service: textures, sounds, notecards, scripts, objects... A record never carries its data: an image of any type (texture, TGA, JPEG) is asked as a picture (`/grids/{grid}/assets/{id}.{format}`, the grid keeps textures as JPEG 2000 and the engine converts them and keeps the result for a day), the other types as they are.',
            'attributes' => [
                'uuid' => ['string', ''],
                'name' => ['string', ''],
                'slug' => ['string', ''],
                'description' => ['string', ''],
                'type' => ['string', 'texture, sound, notecard, script, object, mesh...'],
                'typeNumber' => ['integer', 'The number OpenSimulator gives the type'],
                'mime' => ['string', 'The content type of its data'],
                'local' => ['boolean', 'Kept in the region that made it only'],
                'temporary' => ['boolean', 'Kept for a while only'],
                'flags' => ['integer', ''],
                'creator' => ['string?', 'The UUID of who made it'],
                'created' => ['integer?', 'Unix time'],
                'accessed' => ['integer?', 'Unix time'],
                'size' => ['integer?', 'Bytes of its data'],
            ],
            'filters' => [
                'type' => ['texture', 'sound', 'notecard', 'script', 'object', 'mesh'],
                'local' => ['yes', 'no'],
                'temporary' => ['yes', 'no'],
            ],
            'writable' => [],
        ],
        'destinations' => [
            'scope' => 'grid',
            'summary' => 'The destinations of the guide of a grid',
            'description' =>
                'The places the guide of the grid shows, by category, in the order of its source (`GuideSource` of `[Helpers]`: a file or a URL of "Name|Region URL" lines). The same list feeds the page of the guide and the boards of the scripts.',
            'attributes' => [
                'name' => ['string', 'What the guide shows'],
                'category' => ['string', 'The group it is listed in'],
                'url' => [
                    'string',
                    'The region address it leads to, as the source gives it (grid and region, with the place when there is one)',
                ],
            ],
            'filters' => [],
            'writable' => [],
        ],
        'tasks' => [
            'scope' => 'application',
            'summary' => 'The tasks the application runs now and then',
            'description' =>
                'Cron and pseudo cron: each task has a name and an interval, runs when due, never twice at once, and a failure waits for its next turn. Asked to run, a task runs now. The search registers two: `search-parse` (once a minute: reads the snapshots of the registered simulators that are due) and `events-parse` (every 55 minutes: reads the calendar of events).',
            'attributes' => [
                'label' => ['string', 'What it is for'],
                'every' => ['integer', 'Seconds between two runs'],
                'lastRun' => ['integer?', 'Unix time of the last start'],
                'status' => ['string?', 'ran, failed or running'],
                'message' => ['string', 'What the last run said'],
                'duration' => ['number?', 'Seconds the last run took'],
                'next' => ['integer?', 'Unix time it is due'],
                'due' => ['boolean', 'It runs at the next pass of the cron'],
                'running' => ['boolean', 'Someone is running it now'],
            ],
            'filters' => [],
            'writable' => [],
        ],
        'forms' => [
            'scope' => 'application',
            'summary' => 'What a setup asks',
            'description' =>
                'The fields of the forms (grid, remote-grid, simulator, region), in the standard format of the forms: name, label, type, default, options, condition, follows, group. Whoever shows a form (the terminal, a web page) reads them from here; the engine itself uses them to work out what is not answered.',
            'attributes' => [
                'fields' => ['array', 'The fields, in the order they are asked'],
            ],
            'filters' => [],
            'writable' => [],
        ],
        'fonts' => [
            'scope' => 'application',
            'summary' => 'The fonts the machine has to draw a text',
            'description' => 'The names to give as `font` of a text image (`/text-images`).',
            'attributes' => ['name' => ['string', 'The name of the font']],
            'filters' => [],
            'writable' => [],
        ],
    ];

    /** @return array<string,array{0:string,1:string}> the attributes of a type of resource */
    public static function attributes(string $type): array
    {
        return self::RESOURCES[$type]['attributes'];
    }

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
                    'type' => ['type' => 'string', 'description' => 'A URN that names the kind of problem'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'detail' => ['type' => 'string'],
                    'instance' => ['type' => 'string', 'description' => 'The address that was asked'],
                ],
            ],
        ];
        $tags = [];
        $scopes = ['machine' => 'Machine', 'grid' => 'Grid', 'application' => 'Application'];
        $groups = [];
        foreach (self::RESOURCES as $type => $resource) {
            $schema = self::schemaName($type);
            $one = self::schemaName(rtrim($type, 's'));
            $tag = ucfirst(str_replace('-', ' ', $type));
            $word = strtolower(str_replace('-', ' ', rtrim($type, 's')));
            $groups[$scopes[$resource['scope']]][] = $tag;
            $tags[] = ['name' => $tag, 'description' => $resource['description']];
            $schemas[$schema] = self::schema($type);
            $base = $resource['scope'] === 'grid' ? "/grids/{grid}/$type" : "/$type";
            $parameters = $resource['scope'] === 'grid' ? [self::gridParameter()] : [];
            if (!in_array($type, self::UNPAGED, true)) {
                $paths[$base]['get'] = [
                    'operationId' => "list$schema",
                    'tags' => [$tag],
                    'summary' => "List $type",
                    'description' => $resource['summary'] . ', searched, filtered, sorted and paged.',
                    'parameters' => [...$parameters, ...self::listParameters($resource['filters'])],
                    'responses' => [
                        '200' => self::documentResponse("A page of the $type", $schema, true),
                        '404' => self::problem('The grid is not known here'),
                        '503' => self::problem('What the list is read from is not available'),
                    ],
                ];
            } else {
                $paths[$base]['get'] = [
                    'operationId' => "list$schema",
                    'tags' => [$tag],
                    'summary' => "List $type",
                    'description' => $resource['summary'] . '.',
                    'parameters' => $parameters,
                    'responses' => [
                        '200' => self::documentResponse("The $type", $schema, true),
                        '404' => self::problem('The application has none, or the grid is not known here'),
                    ],
                ];
            }
            $paths["$base/{id}"]['get'] = [
                'operationId' => "get$one",
                'tags' => [$tag],
                'summary' => "Get $word",
                'description' => 'One of the ' . $type . ' by its id.',
                'parameters' => [...$parameters, self::idParameter()],
                'responses' => [
                    '200' => self::documentResponse('The resource', $schema, false),
                    '404' => self::problem('There is none with this id'),
                ],
            ];
            if (isset($resource['create'])) {
                $paths[$base]['post'] = [
                    'operationId' => "create$one",
                    'tags' => [$tag],
                    'summary' => "Make $word",
                    'parameters' => $parameters,
                    'requestBody' => [
                        'required' => true,
                        'content' => [Response::JSON_API => ['schema' => self::input($resource['create'])]],
                    ],
                    'responses' => [
                        '201' => self::documentResponse('Made; its address is in the Location header', $schema, false),
                        '400' => self::problem(
                            'A value is wrong: a name that is not one, one that is taken, no password',
                        ),
                        '503' => self::problem('A database cannot be reached'),
                    ],
                ];
            }
            if ($resource['writable'] !== []) {
                $paths["$base/{id}"]['patch'] = [
                    'operationId' => "update$one",
                    'tags' => [$tag],
                    'summary' => "Change $word",
                    'parameters' => [...$parameters, self::idParameter()],
                    'requestBody' => [
                        'required' => true,
                        'content' => [Response::JSON_API => ['schema' => self::input($resource['writable'])]],
                    ],
                    'responses' => [
                        '200' => self::documentResponse('Changed', $schema, false),
                        '400' => self::problem('A value is wrong'),
                        '404' => self::problem('There is none with this id'),
                        '503' => self::problem('No channel can change it now'),
                    ],
                ];
            }
            if ($resource['deletable'] ?? false) {
                $paths["$base/{id}"]['delete'] = [
                    'operationId' => "delete$one",
                    'tags' => [$tag],
                    'summary' => "Delete $word",
                    'parameters' => [...$parameters, self::idParameter()],
                    'responses' => [
                        '204' => ['description' => 'Deleted'],
                        '404' => self::problem('There is none with this id'),
                        '503' => self::problem('No channel can delete it now'),
                    ],
                ];
            }
        }
        $paths['/grids/{grid}/assets/{id}.{format}']['get'] = [
            'operationId' => 'getAssetMedia',
            'tags' => ['Assets'],
            'summary' => 'Asset as a picture',
            'description' => 'An asset as a page shows it: an image of any type as a picture in the format asked.',
            'parameters' => [
                self::gridParameter(),
                self::idParameter(),
                [
                    'name' => 'format',
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string', 'enum' => ['jpg', 'png', 'gif', 'webp']],
                ],
                [
                    'name' => 'width',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Resize the picture to this width, in pixels',
                    'schema' => ['type' => 'integer', 'minimum' => 1],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The picture, or the asset as it is',
                    'content' => ['image/*' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                ],
                '404' => [
                    'description' => 'There is no such asset, or it cannot be converted: the picture of nothing',
                    'content' => ['image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                ],
            ],
        ];
        $paths['/grids/{grid}/motd']['get'] = [
            'operationId' => 'getMotd',
            'tags' => ['Message of the day'],
            'summary' => 'Message of the day',
            'description' =>
                'Plain text: `WelcomeMessage` of `[LoginService]`, else a welcome to the grid. `<USERNAME>` stands for the name of the avatar, replaced when `username` is given. This is what `MessageUrl` of the Robust config can point to.',
            'parameters' => [
                self::gridParameter(),
                [
                    'name' => 'username',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The name that replaces `<USERNAME>`',
                    'schema' => ['type' => 'string'],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The message',
                    'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
                ],
                '404' => self::problem('The grid is not known here'),
            ],
        ];
        $paths['/text-images']['get'] = [
            'operationId' => 'getTextImage',
            'tags' => ['Text images'],
            'summary' => 'Text as an image',
            'description' =>
                'A 1024 pixels square PNG with the text centered, to put on a prim where dynamic textures are not allowed, or to lighten the simulator. The fonts the machine has are listed at `/fonts`; one it does not have is replaced by DejaVu-Sans.',
            'parameters' => [
                [
                    'name' => 'text',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The text (500 characters at most); the name of the font when there is none',
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'font',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string', 'default' => 'Impact'],
                ],
                [
                    'name' => 'size',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The size of the font, up to 1000',
                    'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 120],
                ],
                [
                    'name' => 'color',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The color of the text: a name or #rrggbb',
                    'schema' => ['type' => 'string', 'default' => 'white'],
                ],
                [
                    'name' => 'background',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'The color of the background: a name or #rrggbb',
                    'schema' => ['type' => 'string', 'default' => 'black'],
                ],
                [
                    'name' => 'download',
                    'in' => 'query',
                    'required' => false,
                    'description' => 'Offer the picture as a file instead of showing it',
                    'schema' => ['type' => 'boolean', 'default' => false],
                ],
            ],
            'responses' => [
                '200' => [
                    'description' => 'The picture',
                    'content' => ['image/png' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
                ],
                '400' => self::problem('The color or the background is not one'),
                '503' => self::problem('The machine cannot draw: php-imagick is missing'),
            ],
        ];
        $paths['/tasks/{id}/runs']['post'] = [
            'operationId' => 'runTask',
            'tags' => ['Tasks'],
            'summary' => 'Run a task',
            'description' => 'Run a task now, whether it is due or not.',
            'parameters' => [self::idParameter()],
            'responses' => [
                '200' => [
                    'description' => 'The task ran',
                    'content' => [
                        Response::JSON_API => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'string'],
                                            'message' => ['type' => 'string'],
                                            'duration' => ['type' => 'number'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '404' => self::problem('There is no task with this name'),
                '500' => self::problem('The task failed: the message says why'),
            ],
        ];

        $endpoints = Endpoints::document();
        $tags = [...$tags, ...$endpoints['tags']];
        $paths = [...$paths, ...$endpoints['paths']];
        foreach ($endpoints['groups'] as $group => $names) {
            $groups[$group] = [...$groups[$group] ?? [], ...$names];
        }
        isset($paths['/grids/{grid}/destinations']['get']) &&
            ($paths['/grids/{grid}/destinations']['get'] = Endpoints::destinations(
                $paths['/grids/{grid}/destinations']['get'],
            ));

        // What needs no key is what the viewers, the simulators and the visitors call (Router::PUBLIC)
        foreach (Router::PUBLIC as $public) {
            [$method, $path] = explode(' ', $public, 2);
            isset($paths[$path][strtolower($method)]) && ($paths[$path][strtolower($method)]['security'] = []);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'OpenSim engine',
                'version' => $version,
                'license' => ['name' => 'AGPL-3.0-or-later', 'identifier' => 'AGPL-3.0-or-later'],
                'description' =>
                    "The grids, the simulators and the services of an install, and what is in the databases of a grid, as JSON:API resources; the errors are problem details (RFC 9457).\n\nA list takes `search`, `sort` (`-` before a column for the descending order), `filter[<name>]`, `page[number]` and `page[size]`.\n\n## Authentication\n\nThe API is closed: what manages the machine and the grids (the resources, the tasks, the health) needs an API key, sent as `Authorization: Bearer <key>`. A key is made on the machine and shown once: `opensim api-key create <label>`; `opensim api-key list` lists them without what opens them, `opensim api-key revoke <id>` closes one. A key opens everything for now. To try the API from this documentation, open the [test page](try.html), enter the address of the API and click Authorize.\n\nWhat the viewers, the simulators and the visitors of a site call needs none (the message of the day, the guide, the pictures, the status of the grid, the pages): the operations marked as open. The calls in a format of OpenSimulator and the viewers (search, registration, offline messages, currency, land tools) are protected by what the protocol has: the secure session of an avatar, the secret of a region, the key of the money server.",
            ],
            'servers' => [['url' => '/', 'description' => 'Where the application that uses the engine mounts the API']],
            // Closed unless an operation says it is open (`security: []`)
            'security' => [['ApiKey' => []]],
            'tags' => $tags,
            // The sections of the documentation: the machine, a grid, the application, the helpers, Robust
            'x-tagGroups' => array_map(
                static fn(string $name, array $tags): array => ['name' => $name, 'tags' => $tags],
                array_keys($groups),
                array_values($groups),
            ),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'ApiKey' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'osk_...',
                        'description' =>
                            'A key made on the machine with `opensim api-key create`, sent as `Authorization: Bearer <key>`.',
                    ],
                ],
                'schemas' => $schemas,
            ],
        ];
    }

    public static function json(string $version = '1'): string
    {
        return json_encode(
            self::document($version),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    public static function yaml(string $version = '1'): string
    {
        return Yaml::dump(
            self::document($version),
            12,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE,
        );
    }

    private static function schemaName(string $type): string
    {
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $type)));
    }

    /** @return array<string,mixed> the resource as JSON:API shows it: type, id, attributes */
    private static function schema(string $type): array
    {
        $properties = [];
        foreach (self::RESOURCES[$type]['attributes'] as $name => [$kind, $description]) {
            $nullable = str_ends_with($kind, '?');
            $json = rtrim($kind, '?');
            $properties[$name] =
                ['type' => $nullable ? [$json, 'null'] : $json] +
                ($description !== '' ? ['description' => $description] : []);
        }

        return [
            'type' => 'object',
            'description' => self::RESOURCES[$type]['summary'],
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['const' => $type],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => $properties],
                'links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string']]],
            ],
        ];
    }

    /**
     * @param array<string,array{0:string,1:string}> $fields
     * @return array<string,mixed>
     */
    private static function input(array $fields): array
    {
        $properties = [];
        foreach ($fields as $name => [$kind, $description]) {
            $properties[$name] = ['type' => $kind] + ($description !== '' ? ['description' => $description] : []);
        }

        return [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'properties' => ['attributes' => ['type' => 'object', 'properties' => $properties]],
                ],
            ],
        ];
    }

    /**
     * @param array<string,list<string>> $filters
     * @return list<array<string,mixed>>
     */
    private static function listParameters(array $filters): array
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
        foreach ($filters as $name => $values) {
            $parameters[] = [
                'name' => "filter[$name]",
                'in' => 'query',
                'schema' => ['type' => 'string', 'enum' => $values],
            ];
        }

        return $parameters;
    }

    /** @return array<string,mixed> */
    public static function gridParameter(): array
    {
        return [
            'name' => 'grid',
            'in' => 'path',
            'required' => true,
            'description' => 'The nick of the grid',
            'schema' => ['type' => 'string'],
        ];
    }

    /** @return array<string,mixed> */
    private static function idParameter(): array
    {
        return ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
    }

    /** @return array<string,mixed> */
    private static function documentResponse(string $description, string $schema, bool $list): array
    {
        $item = ['$ref' => "#/components/schemas/$schema"];

        return [
            'description' => $description,
            'content' => [
                Response::JSON_API => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => $list
                            ? ['data' => ['type' => 'array', 'items' => $item], 'meta' => ['type' => 'object']]
                            : ['data' => $item],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function problem(string $description): array
    {
        return [
            'description' => $description,
            'content' => [Problem::CONTENT_TYPE => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
        ];
    }
}
