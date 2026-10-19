<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\GridConfig;
use OpenSim\Controllers\Controller;
use OpenSim\Controllers\RobustController;
use OpenSim\Data\Collection;
use OpenSim\Data\Database;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Provisioning\Slug;
use OpenSim\Runtime\Environment;

/**
 * A service of a grid: what its simulators and its users ask for the assets, the inventories, the profiles, the logins. A service
 * belongs to its grid. It is served by a Robust of the grid (a grid has one or several, each serving one or several services), or
 * by something the grid only declares: the address a simulator is given for it (`AssetServerURI`, `ProfileServiceURL`) is the one of
 * a server somewhere else.
 *
 *     $grid->services();                      // the services the grid has, served here or declared
 *     $grid->service('asset')->provider();    // the Robust that serves the assets
 *     $simulator->service('profile')->url();  // the profile service as this simulator reaches it
 *
 * A simulator inherits the services of its grid and answers for them: asked for a service, it gives the address its own config says
 * (a simulator may use a service that is not its grid's), else the one of its grid.
 */
final class Service extends InstalledModel
{
    /** Reached by the simulators of the grid, on its private port. */
    public const PRIVATE = 'private';

    /** Reached by the viewers and by the other grids, on its public port. */
    public const PUBLIC = 'public';

    /** A web service: an address on a web site, not a port of Robust. */
    public const WEB = 'web';

    /** The format of its requests and answers is fixed by OpenSimulator or by the viewers: the address is the grid's to choose. */
    public const FIXED = 'fixed';

    /** The format is the engine's own (see Contract): the old scripts that answered in their own are adapted. */
    public const STANDARD = 'standard';

    /** A page for people (the web search, the status of the grid), no format to keep. */
    public const PAGE = 'page';

    /**
     * The services OpenSimulator has, by key: the connectors of `[ServiceList]` that serve it, the port they are on, and the setting a
     * simulator is given its address in (section, key), when it has one.
     */
    private const CATALOG = [
        'asset' => [
            'connectors' => ['AssetServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['AssetService', 'AssetServerURI'],
            'database' => Database::Assets,
        ],
        'inventory' => [
            'connectors' => ['InventoryInConnector'],
            'port' => self::PRIVATE,
            'setting' => ['InventoryService', 'InventoryServerURI'],
            'database' => Database::Robust,
        ],
        'grid' => [
            'connectors' => ['GridServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['GridService', 'GridServerURI'],
            'database' => Database::Robust,
        ],
        'estate' => [
            'connectors' => ['EstateDataService'],
            'port' => self::PRIVATE,
            'setting' => ['EstateService', 'EstateServerURI'],
            'database' => Database::Estate,
        ],
        'authentication' => [
            'connectors' => ['AuthenticationServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['AuthenticationService', 'AuthenticationServerURI'],
            'database' => Database::Robust,
        ],
        'avatar' => [
            'connectors' => ['AvatarServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['AvatarService', 'AvatarServerURI'],
            'database' => Database::Robust,
        ],
        'agent-preferences' => [
            'connectors' => ['AgentPreferencesServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['AgentPreferencesService', 'AgentPreferencesServerURI'],
            'database' => Database::Robust,
        ],
        'presence' => [
            'connectors' => ['PresenceServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['PresenceService', 'PresenceServerURI'],
            'database' => Database::Robust,
        ],
        'user-account' => [
            'connectors' => ['UserAccountServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['UserAccountService', 'UserAccountServerURI'],
            'database' => Database::Robust,
        ],
        'grid-user' => [
            'connectors' => ['GridUserServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['GridUserService', 'GridUserServerURI'],
            'database' => Database::Robust,
        ],
        'friends' => [
            'connectors' => ['FriendsServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['FriendsService', 'FriendsServerURI'],
            'database' => Database::Robust,
        ],
        'map-image' => [
            'connectors' => ['MapAddServiceConnector', 'MapGetServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['MapImageService', 'MapImageServerURI'],
            'database' => null,
        ],
        'mute-list' => [
            'connectors' => ['MuteListConnector'],
            'port' => self::PRIVATE,
            'setting' => ['MuteListService', 'MuteListServerURI'],
            'database' => Database::Robust,
        ],
        'bakes' => [
            'connectors' => ['BakedTextureService'],
            'port' => self::PRIVATE,
            'setting' => ['XBakes', 'URL'],
            'database' => Database::Robust,
        ],
        'groups' => [
            'connectors' => ['GroupsServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['Groups', 'GroupsServerURI'],
            'database' => Database::Robust,
        ],
        'offline' => [
            'connectors' => ['OfflineIMServiceConnector'],
            'port' => self::PRIVATE,
            'setting' => ['Messaging', 'OfflineMessageURL'],
            'database' => Database::Offline,
            'kind' => self::STANDARD,
            'legacy' => 'offline',
            'standard' => 'POST /offline-messages',
        ],
        'profile' => [
            'connectors' => ['UserProfilesServiceConnector'],
            'port' => self::PUBLIC,
            'setting' => ['UserProfiles', 'ProfileServiceURL'],
            'database' => Database::Profiles,
        ],
        'login' => [
            'connectors' => ['LLLoginServiceInConnector'],
            'port' => self::PUBLIC,
            'setting' => null,
            'database' => null,
        ],
        // The /get_grid_info of OpenSimulator (Robust answers it): the engine reads it, it is no endpoint of the API
        'get-grid-info' => [
            'connectors' => ['GridInfoServerInConnector'],
            'port' => self::PUBLIC,
            'setting' => null,
            'database' => null,
        ],
        'gatekeeper' => [
            'connectors' => ['GatekeeperServiceInConnector'],
            'port' => self::PUBLIC,
            'setting' => null,
            'database' => null,
        ],
        'user-agent' => [
            'connectors' => ['UserAgentServerConnector'],
            'port' => self::PUBLIC,
            'setting' => null,
            'database' => null,
        ],
        'hg-friends' => [
            'connectors' => ['HGFriendsServerConnector'],
            'port' => self::PUBLIC,
            'setting' => null,
            'database' => null,
        ],
        'hg-asset' => [
            'connectors' => ['HGAssetServiceConnector'],
            'port' => self::PUBLIC,
            'setting' => null,
            'database' => null,
        ],
        'hg-inventory' => [
            'connectors' => ['HGInventoryServiceConnector'],
            'port' => self::PUBLIC,
            'setting' => null,
            'database' => null,
        ],
        'hg-groups' => [
            'connectors' => ['HGGroupsServiceConnector'],
            'port' => self::PUBLIC,
            'setting' => null,
            'database' => null,
        ],
        'map' => [
            'connectors' => ['MapGetServiceConnector'],
            'port' => self::PUBLIC,
            'setting' => null,
            'database' => null,
            'kind' => self::FIXED,
        ],
        'query' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['Search', 'SearchURL'],
            'database' => Database::Search,
            'default' => 'query',
            'kind' => self::FIXED,
            'legacy' => 'query',
        ],
        'search' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['GridInfoService', 'search'],
            'role' => 'Robust',
            'also' => [['Robust', 'LoginService', 'SearchURL']],
            'info' => 'search',
            'default' => 'search',
            'database' => null,
            'kind' => self::PAGE,
        ],
        'welcome' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['GridInfoService', 'welcome'],
            'role' => 'Robust',
            'info' => 'welcome',
            'default' => 'welcome',
            'database' => null,
            'kind' => self::PAGE,
        ],
        'web-profile' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['GridInfoService', 'web_profile_url'],
            'role' => 'Robust',
            'info' => 'web_profile_url',
            'database' => null,
            'kind' => self::PAGE,
        ],
        'currency' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['GridInfoService', 'economy'],
            'role' => 'Robust',
            'also' => [['Robust', 'Economy', 'economy']],
            'info' => 'economy',
            'append' => 'currency.php',
            'default' => 'currency',
            'database' => Database::Economy,
            'kind' => self::FIXED,
            'legacy' => 'currency',
        ],
        'landtool' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['GridInfoService', 'economy'],
            'role' => 'Robust',
            'also' => [['Robust', 'Economy', 'economy']],
            'info' => 'economy',
            'append' => 'landtool.php',
            'default' => 'landtool',
            'database' => null,
            'kind' => self::FIXED,
            'legacy' => 'landtool',
        ],
        'register' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['DataSnapshot', 'DATA_SRV_*'],
            'default' => 'register',
            'database' => Database::Search,
            'kind' => self::STANDARD,
            'legacy' => 'register',
            'standard' => 'POST /search/hosts',
        ],
        'motd' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['LoginService', 'MessageUrl'],
            'role' => 'Robust',
            'default' => 'motd',
            'database' => null,
            'kind' => self::STANDARD,
            'legacy' => 'motd',
            'standard' => 'GET /motd',
        ],
        'guide' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['LoginService', 'DestinationGuide'],
            'role' => 'Robust',
            'default' => 'guide',
            'database' => null,
            'kind' => self::STANDARD,
            'legacy' => 'guide',
            'standard' => 'GET /destinations',
        ],
        'grid-status' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['GridInfoService', 'GridStatus'],
            'role' => 'Robust',
            'also' => [['Helpers', 'Helpers', 'GridStatusURL']],
            'info' => 'GridStatus',
            'default' => 'grid_status',
            'database' => null,
            'kind' => self::PAGE,
        ],
        'grid-status-rss' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['GridInfoService', 'GridStatusRSS'],
            'role' => 'Robust',
            'info' => 'GridStatusRSS',
            'database' => null,
            'kind' => self::FIXED,
        ],
        'parser' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => null,
            'default' => 'parser',
            'database' => null,
            'kind' => self::STANDARD,
            'legacy' => 'parser',
            'standard' => 'POST /tasks/search-parse/runs',
        ],
        'events-parser' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => null,
            'default' => 'eventsparser',
            'database' => null,
            'kind' => self::STANDARD,
            'legacy' => 'eventsparser',
            'standard' => 'POST /tasks/events-parse/runs',
        ],
        'text-images' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => null,
            'default' => 'textgen',
            'database' => null,
            'kind' => self::STANDARD,
            'legacy' => 'textgen',
            'standard' => 'GET /text-images',
        ],
        'assets' => [
            'connectors' => [],
            'port' => self::WEB,
            'setting' => ['Helpers', 'AssetsURL'],
            'role' => 'Helpers',
            'default' => 'assets',
            'database' => Database::Assets,
            'kind' => self::STANDARD,
            'standard' => 'GET /assets/{id}.{format}',
        ],
        'instant-message' => [
            'connectors' => ['InstantMessageServerConnector'],
            'port' => self::PUBLIC,
            'setting' => null,
            'database' => null,
        ],
    ];

    /** What a standalone serves by itself: the services of the grid, the ones of the hypergrid when it is one. */
    private const STANDALONE = [
        'asset',
        'inventory',
        'grid',
        'estate',
        'authentication',
        'avatar',
        'agent-preferences',
        'presence',
        'user-account',
        'grid-user',
        'friends',
        'map-image',
        'mute-list',
        'profile',
        'login',
        'get-grid-info',
    ];

    private const STANDALONE_HYPERGRID = [
        'gatekeeper',
        'user-agent',
        'hg-friends',
        'hg-asset',
        'hg-inventory',
        'instant-message',
    ];

    /** @var array{0:?string,1:?string}|null the address and its source, worked out once */
    private ?array $resolved = null;

    /** @var array{0:?string}|null the address declared for the simulators of the grid, read once */
    private ?array $declaration = null;

    /**
     * @param string $key one of keys()
     * @param ?Simulator $viewer the simulator that asks, null for the grid itself
     */
    public function __construct(
        Environment $environment,
        private readonly Grid $grid,
        private readonly string $key,
        private readonly ?Simulator $viewer = null,
    ) {
        parent::__construct($environment);
        isset(self::CATALOG[$key]) || throw new \InvalidArgumentException("unknown service '$key'");
    }

    /**
     * The keys of the services OpenSimulator has.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::CATALOG);
    }

    /** What a service is for, by key. */
    public static function labels(): array
    {
        return [
            'asset' => _('Assets'),
            'inventory' => _('Inventories'),
            'grid' => _('Regions of the grid'),
            'estate' => _('Estates'),
            'authentication' => _('Authentication'),
            'avatar' => _('Avatar appearance'),
            'agent-preferences' => _('Agent preferences'),
            'presence' => _('Presence'),
            'user-account' => _('User accounts'),
            'grid-user' => _('Last positions of the users'),
            'friends' => _('Friends'),
            'map-image' => _('Map tiles'),
            'mute-list' => _('Mute lists'),
            'bakes' => _('Baked textures'),
            'groups' => _('Groups'),
            'offline' => _('Offline messages'),
            'profile' => _('Profiles'),
            'login' => _('Login'),
            'get-grid-info' => _('Grid information of Robust (get_grid_info)'),
            'gatekeeper' => _('Gatekeeper (hypergrid arrivals)'),
            'user-agent' => _('User agent (hypergrid departures)'),
            'hg-friends' => _('Hypergrid friends'),
            'hg-asset' => _('Hypergrid assets'),
            'hg-inventory' => _('Hypergrid inventories'),
            'hg-groups' => _('Hypergrid groups'),
            'instant-message' => _('Instant messages between grids'),
            'map' => _('Map tiles for the web'),
            'query' => _('Search API (the simulators query it)'),
            'search' => _('Web search'),
            'welcome' => _('Welcome page'),
            'web-profile' => _('Web page of a profile'),
            'currency' => _('Currency'),
            'landtool' => _('Land purchase'),
            'register' => _('Registration of the simulators in the search'),
            'motd' => _('Message of the day'),
            'guide' => _('Destination guide'),
            'grid-status' => _('Status of the grid'),
            'grid-status-rss' => _('Status of the grid, feed'),
            'parser' => _('Reading of the search data of the hosts'),
            'events-parser' => _('Reading of the events'),
            'text-images' => _('Images of a text'),
            'assets' => _('Assets as images for the web'),
        ];
    }

    /**
     * The services a grid has, served by its Robust instances or declared in the config of its simulators.
     *
     * Options: `search` (words of the key, the name or the address), `sort` ("name", "key", "grid"), `filters` (`served` yes or no,
     * `port` private or public), `page` and `perPage`.
     *
     * @param ?Simulator $viewer the simulator that asks, null for the grid itself
     */
    public static function of(?Grid $grid, ?Simulator $viewer = null, array $options = []): Collection
    {
        $services = [];
        foreach ($grid === null ? [] : self::keys() as $key) {
            $service = new self($grid->environment(), $grid, $key, $viewer);
            $service->exists() && ($services[] = $service);
        }

        return self::collect($services, $options);
    }

    /** The services of all the grids of the machine. */
    public static function all(Environment $environment, array $options = []): Collection
    {
        $services = [];
        foreach (Grid::all($environment) as $grid) {
            foreach (self::of($grid)->items() as $service) {
                $services[] = $service;
            }
        }

        return self::collect($services, $options);
    }

    /** A service by its slug: the grid, an underscore, the key (`vonda_asset`). */
    public static function find(Environment $environment, string $id): ?static
    {
        foreach (self::all($environment)->items() as $service) {
            if ($service->id() === $id) {
                return $service;
            }
        }

        return null;
    }

    /** @param list<self> $services */
    private static function collect(array $services, array $options): Collection
    {
        $yesNo = [
            static fn(self $service, string $value): bool => $service->isServed() === ($value === 'yes'),
            ['yes', 'no'],
        ];

        return self::listing(
            $services,
            $options,
            static fn(
                self $service,
            ): string => "{$service->key} {$service->name()} {$service->url()} {$service->grid->nick()}",
            [
                'name' => static fn(self $service): string => $service->name(),
                'key' => static fn(self $service): string => $service->key,
                'grid' => static fn(self $service): string => $service->grid->nick(),
            ],
            [
                'served' => $yesNo,
                'port' => [
                    static fn(self $service, string $value): bool => $service->port() === $value,
                    [self::PRIVATE, self::PUBLIC, self::WEB],
                ],
            ],
            [
                ['name' => 'name', 'label' => _('Service'), 'sortable' => true],
                ['name' => 'grid', 'label' => _('Grid'), 'sortable' => true],
                ['name' => 'url', 'label' => _('Address')],
                ['name' => 'provider', 'label' => _('Served by')],
            ],
        );
    }

    /** `vonda_asset`: the grid and the service, as the slug of a simulator has its grid. */
    public function id(): string
    {
        return $this->slug();
    }

    public function slug(): string
    {
        return Slug::simulator($this->grid->nick(), $this->key);
    }

    /** What the service is for ("Assets"). */
    public function name(): string
    {
        return self::labels()[$this->key];
    }

    /** The key of the service in the catalog (`asset`, `profile`). */
    public function key(): string
    {
        return $this->key;
    }

    public function grid(): Grid
    {
        return $this->grid;
    }

    /** The simulator that asks, null when it is the grid. */
    public function viewer(): ?Simulator
    {
        return $this->viewer;
    }

    /** Whether it is a web service: an address on a web site (the helpers), not a port of Robust. */
    public function isWeb(): bool
    {
        return $this->port() === self::WEB;
    }

    /** Whether its format is fixed by OpenSimulator or the viewers (self::FIXED), or the engine's own (self::STANDARD); null for a service of Robust. */
    public function kind(): ?string
    {
        return self::CATALOG[$this->key]['kind'] ?? null;
    }

    /** The name of the script that answered it before the engine had a standard for it (`query`, `register`...). */
    public function legacy(): ?string
    {
        return self::CATALOG[$this->key]['legacy'] ?? null;
    }

    /** The standard address of a service whose format is the engine's own (`GET /motd`), null when it has none. */
    public function standard(): ?string
    {
        return self::CATALOG[$this->key]['standard'] ?? null;
    }

    /** The port it is reached on: self::PRIVATE or self::PUBLIC. */
    public function port(): string
    {
        return self::CATALOG[$this->key]['port'];
    }

    /**
     * The connectors of `[ServiceList]` that serve it.
     *
     * @return list<string>
     */
    public function connectors(): array
    {
        return self::CATALOG[$this->key]['connectors'];
    }

    /** The database it stores in, null for a service that stores nothing. */
    public function database(): ?Database
    {
        return self::CATALOG[$this->key]['database'];
    }

    /**
     * Where a simulator is given its address: section and key of its config, null for a service the simulators do not ask for.
     *
     * @return ?array{0:string,1:string}
     */
    public function setting(): ?array
    {
        return self::CATALOG[$this->key]['setting'];
    }

    /**
     * What serves it: a Robust of the grid, or the simulator of a standalone grid. Null when nothing does.
     */
    public function provider(): ?Controller
    {
        if ($this->grid->isStandalone()) {
            $serves =
                in_array($this->key, self::STANDALONE, true) ||
                ($this->grid->isHypergrid() && in_array($this->key, self::STANDALONE_HYPERGRID, true));

            return $serves ? ($this->grid->simulators()[0] ?? null)?->controller() : null;
        }
        foreach ($this->grid->robusts() as $robust) {
            foreach ($this->connectors() as $connector) {
                if ($robust->serves($connector)) {
                    return $robust;
                }
            }
        }

        return null;
    }

    /** Whether a Robust of the grid serves it. */
    public function isServed(): bool
    {
        return $this->provider() !== null;
    }

    /** Whether the address a simulator is given is the one of a server the grid does not run. */
    public function isExternal(): bool
    {
        return !$this->isServed() && $this->declared() !== null;
    }

    /** Whether the grid has it: served by a Robust, or declared for its simulators. */
    public function exists(): bool
    {
        // What the grid says, or the helpers answer at their default address
        return $this->isServed() ||
            $this->declared() !== null ||
            $this->live() !== null ||
            $this->helperAddress() !== null;
    }

    /**
     * The link to the service, where it is reached. A simulator gives the one its config says, else the one of its grid: the Robust that
     * serves it (its base URL and its private or public port), else the address declared for the simulators of the grid.
     */
    public function url(): ?string
    {
        if ($this->viewer !== null && ($own = $this->declaredBy($this->viewer)) !== null) {
            return $own;
        }
        return $this->resolved()[0];
    }

    /**
     * Where the address comes from: `provider` (the Robust or the simulator that serves it), `simulator` (its own config), `live`
     * (the running grid says it at get_grid_info), `config`, `default` (where the helpers answer it); null when it has none.
     */
    public function source(): ?string
    {
        if ($this->viewer !== null && $this->declaredBy($this->viewer) !== null) {
            return 'simulator';
        }

        return $this->resolved()[1];
    }

    /**
     * The address and where it comes from, in the order of trust: what serves it, what the running grid says (a config changed
     * since is not what the viewers are told), what the config says, where the helpers answer it.
     *
     * @return array{0:?string,1:?string}
     */
    private function resolved(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }
        $append = self::CATALOG[$this->key]['append'] ?? '';
        $with = static fn(string $base): string => $append !== '' ? rtrim($base, '/') . '/' . $append : $base;
        $provider = $this->provider();
        if ($provider instanceof RobustController) {
            $port = $this->port() === self::PUBLIC ? $provider->publicPort() : $provider->privatePort();
            $base = $provider->setting('Const', 'BaseURL') ?: 'http://' . ($provider->baseHostname() ?? 'localhost');

            return $this->resolved = [$port !== null ? "$base:$port" : $base, 'provider'];
        }
        if ($provider !== null) {
            // The simulator of a standalone answers for everything on its own port
            return $this->resolved = [
                ($provider->setting('Const', 'BaseURL') ?: 'http://' . $this->grid->baseHostname()) .
                ':' .
                $this->grid->publicPort(),
                'provider',
            ];
        }
        if (($live = $this->live()) !== null) {
            return $this->resolved = [$with($live), 'live'];
        }
        if (($declared = $this->declared()) !== null) {
            return $this->resolved = [$with($declared), 'config'];
        }
        $default = $this->helperAddress();

        return $this->resolved = [$default, $default !== null ? 'default' : null];
    }

    /** What the running grid says for it at get_grid_info, null when it does not say, or does not answer. */
    private function live(): ?string
    {
        $info = self::CATALOG[$this->key]['info'] ?? null;
        if ($info === null || !$this->grid->gridInfoIsLive()) {
            return null;
        }
        $value = $this->grid->gridInfo()[$info] ?? '';

        return $value !== '' ? $value : null;
    }

    /** Served by a Robust that is enabled, or declared (a server somewhere else is the business of whoever runs it). */
    public function isEnabled(): bool
    {
        return $this->provider()?->isEnabled() ?? $this->declared() !== null;
    }

    public function enable(): bool
    {
        throw ChannelUnavailable::for(
            'enable the ' . $this->key . ' service',
            "it is served by the Robust of the grid: enable the grid, or the feature that brings it ($this->key)",
        );
    }

    public function disable(): void
    {
        throw ChannelUnavailable::for(
            'disable the ' . $this->key . ' service',
            "it is served by the Robust of the grid: disable the grid, or the feature that brings it ($this->key)",
        );
    }

    public function detail(): array
    {
        $setting = $this->setting();

        return [
            'id' => $this->id(),
            'key' => $this->key,
            'name' => $this->name(),
            'slug' => $this->slug(),
            'grid' => $this->grid->nick(),
            'simulator' => $this->viewer?->id(),
            'url' => $this->url(),
            'port' => $this->port(),
            'provider' => $this->provider()?->name(),
            'served' => $this->isServed(),
            'external' => $this->isExternal(),
            'connectors' => $this->connectors(),
            'section' => $setting[0] ?? null,
            'setting' => $setting[1] ?? null,
            'database' => $this->database()?->value,
            'web' => $this->isWeb(),
            'kind' => $this->kind(),
            'legacy' => $this->legacy(),
            'standard' => $this->standard(),
            'source' => $this->source(),
        ];
    }

    /** Where the helpers of the grid answer a web service when the grid says nothing of it, null when it has no helpers. */
    private function helperAddress(): ?string
    {
        $default = self::CATALOG[$this->key]['default'] ?? null;

        return $default !== null && $this->isWeb() ? ($this->grid->helperUrl($default) ?: null) : null;
    }

    /**
     * The settings of the config that give the address, the first that has a value wins: role, section, key.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    private function settings(): array
    {
        $entry = self::CATALOG[$this->key];
        $settings = isset($entry['setting'])
            ? [[$entry['role'] ?? 'OpenSim', $entry['setting'][0], $entry['setting'][1]]]
            : [];

        return [...$settings, ...$entry['also'] ?? []];
    }

    /** The address the config of the simulators of the grid gives for it, null when it gives none. */
    private function declared(): ?string
    {
        if ($this->viewer !== null) {
            return $this->declaredBy($this->viewer) ?? $this->gridDeclaration();
        }

        return $this->gridDeclaration();
    }

    private function gridDeclaration(): ?string
    {
        if ($this->declaration === null) {
            // What the grid says: the OpenSim.ini of its folder for the simulators, the Robust config for what Robust tells, the
            // Helpers.ini for what has no official place
            $tree =
                $this->grid->directory() !== '' && is_dir($this->grid->directory())
                    ? GridConfig::fromFolder($this->grid->directory(), ['Helpers' => 'Helpers.ini'])->tree()
                    : [];
            $value = null;
            foreach ($this->settings() as [$role, $section, $key]) {
                $in = $tree[$role][$section] ?? [];
                $value = self::valueOf(is_array($in) ? $in : [], $key);
                if ($value !== null) {
                    break;
                }
            }
            $this->declaration = [$value];
        }

        return $this->declaration[0];
    }

    /** The value of a setting in a section; a key ending with `*` is the first key that starts so. */
    private static function valueOf(array $section, string $key): ?string
    {
        if (str_ends_with($key, '*')) {
            foreach ($section as $name => $value) {
                if (str_starts_with((string) $name, rtrim($key, '*')) && is_string($value) && $value !== '') {
                    return $value;
                }
            }

            return null;
        }
        $value = $section[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function declaredBy(Simulator $simulator): ?string
    {
        $setting = $this->setting();
        // What Robust tells is not in the config of a simulator
        if ($setting === null || (self::CATALOG[$this->key]['role'] ?? 'OpenSim') !== 'OpenSim') {
            return null;
        }

        return self::valueOf($simulator->settings()[$setting[0]] ?? [], $setting[1]);
    }
}
