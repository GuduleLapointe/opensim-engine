<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\ConnectionString;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Database;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\LocationFinder;
use OpenSim\Provisioning\Slug;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\Result;

/**
 * A grid: the folder `<etc>/grids/<nick>` that holds its Robust config, its description (`<nick>.conf`) and the configs of
 * its simulators (`sims/<name>.ini`). A grid is run from this machine (its Robust, its simulators) or only described here, its
 * Robust being elsewhere.
 *
 * The services of a grid (its Robust) start, stop and restart on their own: its simulators do not need to restart with them.
 * They are included when asked, and then stop first and start last.
 *
 * It is the same object whatever it is read from, and it owns what is known of the grid. Where it comes from (`sources()`, `in()`):
 *
 *  - its folder on this machine (`at()`, `find()`, `all()`): the Robust config and the description;
 *  - a plan, a grid that is not made yet (`planned()`);
 *  - a description given (`described()`): what the setup asks of a grid run elsewhere, a test;
 *  - what the grid says of itself at its public port, /get_grid_info (`announced()`).
 *
 * What a source does not tell has the value of a grid by the book (DEFAULTS). The xmlrpc calls of the hypergrid, the tables of
 * OpenSimSearch, the console and the database give the same properties when they are read.
 */
final class Grid implements \OpenSim\Interfaces\Enablable
{
    /** The files that hold the Robust config, the first that exists is the one. */
    private const ROBUST_FILES = ['Robust.HG.ini', 'Robust.ini'];

    /** Where a grid is known from. */
    public const FOLDER = 'folder';
    public const PLAN = 'plan';
    public const DESCRIBED = 'described';
    public const ANNOUNCED = 'announced';

    /** What a grid is when its sources do not say. */
    private const DEFAULTS = [
        'nick' => '',
        'name' => '',
        'slug' => '',
        'hypergrid' => true,
        'regionSpacing' => 0,
        'center' => '',
        'regionFlags' => [],
        'directory' => '',
        'robustIni' => '',
        'coreDirectory' => '',
        'baseHostname' => 'localhost',
        'publicPort' => 8002,
        'privatePort' => 8003,
        'databaseHost' => 'localhost',
        'databaseName' => '',
        'databaseUser' => 'opensim',
        'databasePassword' => '',
        'dataDirectory' => '',
        'cacheDirectory' => '',
        'logsDirectory' => '',
        'remote' => false,
        'preset' => '',
        'planned' => false,
        'connectors' => [],
        'webUrl' => '',
        'helpers' => false,
        'helpersPath' => '/helpers',
        'search' => 'internal',
    ];

    /**
     * @param ?array<string,mixed> $values what the sources say, by the names of DEFAULTS; null for a grid of this machine, read
     *        from its folder when asked
     * @param list<string> $sources
     */
    private function __construct(
        private readonly Environment $environment,
        private readonly string $directory,
        private ?array $values = null,
        private readonly array $sources = [self::FOLDER],
    ) {
    }

    /**
     * The fields of a grid, in the standard format of the forms (see Forms\FieldSet): what the setup asks to make or to change one,
     * and what any other form about a grid (administration) shows. The groups are the screens of the setup.
     *
     * @param array{cores?:array<string,string>,modify?:bool} $options the cores to run it (folder => name; with one there is no
     *        question) and whether the grid exists (its nick is not changed)
     * @return list<array<string,mixed>>
     */
    public static function fields(array $options = []): array
    {
        $cores = $options['cores'] ?? [];

        return [
            ['name' => 'name', 'group' => 'grid', 'type' => 'string', 'required' => true, 'label' => _('Grid name')],
            [
                // The nick names the folder, the database and the instances: it is not changed afterwards
                'name' => 'nick',
                'follows' => ['name'],
                'group' => 'grid',
                'type' => 'string',
                'required' => true,
                'label' => _('Grid nick (snake_case)'),
                'description' => _('Names its folder, its database and its instances'),
                'enable' => !($options['modify'] ?? false),
            ],
            ['name' => 'hypergrid', 'group' => 'grid', 'type' => 'boolean', 'label' => _('Enable Hypergrid?')],
            [
                'name' => 'spacing',
                'group' => 'grid',
                'type' => 'integer',
                'required' => true,
                'label' => _('Free blocks between regions (0: side by side)'),
                'callback' => [self::class, 'validateSpacing'],
            ],
            [
                'name' => 'core',
                'group' => 'grid',
                'type' => 'select',
                'label' => _('OpenSim core to run this grid'),
                'options' => $cores,
                'enable' => count($cores) > 1,
            ],
            ['name' => 'hostname', 'group' => 'network', 'type' => 'string', 'required' => true, 'label' => _('Base hostname')],
            [
                'name' => 'public_port',
                'group' => 'network',
                'type' => 'integer',
                'required' => true,
                'label' => _('Public port'),
                'callback' => [self::class, 'validatePort'],
            ],
            [
                'name' => 'private_port',
                'follows' => ['public_port'],
                'group' => 'network',
                'type' => 'integer',
                'required' => true,
                'label' => _('Private port'),
                'callback' => [self::class, 'validatePort'],
            ],
            [
                'name' => 'center',
                'follows' => ['public_port'],
                'group' => 'network',
                'type' => 'string',
                'required' => true,
                'label' => _('Center of the grid (x,y)'),
                'description' => _('Where new regions are searched from, kept'),
                'callback' => [self::class, 'validateCenter'],
            ],
            [
                'name' => 'console',
                'group' => 'network',
                'type' => 'select',
                'label' => _('Console of the grid'),
                'options' => ['rest' => _('Remote REST console (recommended)'), 'screen' => _('Screen session (on this machine)')],
            ],
            [
                'name' => 'console_port',
                'follows' => ['public_port', 'private_port'],
                'group' => 'network',
                'type' => 'integer',
                'required' => true,
                'label' => _('Console port'),
                'callback' => [self::class, 'validatePort'],
                'condition' => ['console' => 'rest'],
            ],
            ['name' => 'web_url', 'follows' => ['hostname'], 'group' => 'network', 'type' => 'string', 'required' => true, 'label' => _('Web URL')],
            [
                'name' => 'helpers',
                'group' => 'network',
                'type' => 'boolean',
                'label' => _('Serve the economy and the search of the grid with opensim-helpers?'),
            ],
            [
                'name' => 'helpers_path',
                'group' => 'network',
                'type' => 'string',
                'required' => true,
                'label' => _('Helpers path'),
                'description' => _('After the Web URL: the viewers add the name of the script'),
                'callback' => [self::class, 'validateHelpersPath'],
                'condition' => ['helpers' => true],
            ],
            ...self::optionFields($options),
            ['name' => 'database_host', 'group' => 'database', 'type' => 'string', 'required' => true, 'label' => _('Database host')],
            ['name' => 'database_name', 'follows' => ['nick'], 'group' => 'database', 'type' => 'string', 'required' => true, 'label' => _('Database name')],
            ['name' => 'database_user', 'group' => 'database', 'type' => 'string', 'required' => true, 'label' => _('Database user')],
            ['name' => 'database_password', 'group' => 'database', 'type' => 'string', 'required' => true, 'label' => _('Database password')],
        ];
    }

    /** The shared search provider a grid can use instead of its own: the one of 2do.directory. */
    public const SHARED_SEARCH = 'https://2do.directory/helpers';

    /**
     * The options of a new grid, each with its default: what the setup offers to enable or not. They are decided when the grid is made;
     * after that `opensim enable|disable` and the files of the grid decide.
     *
     * @param array{modify?:bool} $options
     * @return list<array<string,mixed>>
     */
    private static function optionFields(array $options): array
    {
        $new = !($options['modify'] ?? false);

        return [
            [
                'name' => 'search',
                'group' => 'options',
                'type' => 'select',
                'label' => _('Search of the grid'),
                'options' => [
                    'internal' => _('Provided by this grid (opensim-helpers)'),
                    'shared' => _('Shared provider (2do.directory)'),
                    'disabled' => _('No search'),
                ],
                'callback' => static fn(mixed $value, array $answers): ?string => $value === 'internal' && !($answers['helpers'] ?? true)
                    ? _('The search of this grid is served by opensim-helpers.')
                    : null,
                'enable' => $new,
            ],
            [
                'name' => 'economy',
                'group' => 'options',
                'type' => 'select',
                'label' => _('Currency'),
                'options' => ['beta' => _('BetaGridLikeMoneyModule (no real money)'), 'gloebit' => _('Gloebit')],
                'enable' => $new,
            ],
            [
                'name' => 'ossl',
                'group' => 'options',
                'type' => 'boolean',
                'label' => _('Allow more OSSL functions to parcel owners (NPC, animations, drawing...)?'),
                'enable' => $new,
            ],
            [
                'name' => 'web_server',
                'group' => 'options',
                'type' => 'select',
                'label' => _('Web server'),
                'options' => ['caddy' => 'Caddy', 'apache' => 'Apache', 'nginx' => 'nginx'],
                'condition' => ['helpers' => true],
                'enable' => $new,
            ],
            [
                'name' => 'web_site',
                'group' => 'options',
                'type' => 'select',
                'label' => _('Web site'),
                'options' => ['helpers' => _('The helpers only'), 'website' => _('The helpers and the mini website (opensim-web)')],
                'condition' => ['helpers' => true],
                'enable' => $new,
            ],
        ];
    }

    /**
     * The fields to join a grid whose Robust runs on another machine (or in another container): what a simulator needs of it, which
     * is kept for the next ones. Its name and its nick are the grid's own when they are left out.
     *
     * @return list<array<string,mixed>>
     */
    public static function remoteFields(): array
    {
        return [
            ['name' => 'address', 'group' => 'remote', 'type' => 'string', 'required' => true, 'label' => _('Address of the grid (host:port)')],
            [
                'name' => 'name',
                'group' => 'remote',
                'type' => 'string',
                'required' => false,
                'label' => _('Grid name'),
                'description' => _('Empty: the grid tells it'),
            ],
            [
                'name' => 'nick',
                'group' => 'remote',
                'type' => 'string',
                'required' => false,
                'label' => _('Grid nick (snake_case)'),
                'description' => _('Empty: the grid tells it'),
            ],
            [
                'name' => 'private_port',
                'group' => 'remote',
                'type' => 'integer',
                'required' => false,
                'label' => _('Private port of the grid (only for its simulators, and this machine is one)'),
                'description' => _('Empty: the public port plus one'),
                'callback' => [self::class, 'validatePort'],
            ],
            ['name' => 'hypergrid', 'group' => 'remote', 'type' => 'boolean', 'label' => _('Does the grid use Hypergrid?')],
        ];
    }

    /** A port number. */
    public static function validatePort(mixed $value): ?string
    {
        return ctype_digit(trim((string) $value)) ? null : _('Enter a port number.');
    }

    /** A number of free blocks between regions. */
    public static function validateSpacing(mixed $value): ?string
    {
        return ctype_digit(trim((string) $value)) && (int) $value <= 50 ? null : _('A number of blocks, 0 to 50.');
    }

    /** A place of the map, x,y. */
    public static function validateCenter(mixed $value): ?string
    {
        return LocationFinder::parse((string) $value) === null ? _('Use x,y (e.g. 8002,8002).') : null;
    }

    /** The path of the helpers on the web site. */
    public static function validateHelpersPath(mixed $value): ?string
    {
        return preg_match('#^/?[A-Za-z0-9._/-]*$#', trim((string) $value)) === 1 ? null : _('A path such as /helpers.');
    }

    /** The grid whose folder is given. */
    public static function at(Environment $environment, string $directory): self
    {
        return new self($environment, rtrim($directory, '/'));
    }

    /** The grid of this nick on this machine, null when there is none: a Robust config or the description of a remote grid. */
    public static function find(Environment $environment, string $nick): ?self
    {
        $directory = rtrim($environment->layout->etcRoot(), '/') . "/grids/$nick";
        if ($nick === '' || preg_match('/^[A-Za-z0-9._-]+$/', $nick) !== 1 || !is_dir($directory)) {
            return null;
        }
        $grid = new self($environment, $directory);

        return $grid->isKnown() ? $grid : null;
    }

    /**
     * The grids of this machine.
     *
     * @return list<self>
     */
    public static function all(Environment $environment): array
    {
        $grids = [];
        foreach (glob(rtrim($environment->layout->etcRoot(), '/') . '/grids/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $grid = new self($environment, $directory);
            $grid->isKnown() && $grids[] = $grid;
        }

        return $grids;
    }

    /**
     * A grid that is planned, to plan its simulator before it is made: nothing of it is on disk or in the database yet.
     */
    public static function planned(Environment $environment, GridPlan $plan): self
    {
        $robustIni = $plan->robustIni();

        return new self($environment, $plan->gridDir, [
            'planned' => true,
            'nick' => $plan->gridNick,
            'name' => $plan->gridName,
            'slug' => $plan->gridSlug,
            'directory' => $plan->gridDir,
            'robustIni' => $robustIni,
            'hypergrid' => $plan->enableHypergrid,
            'regionSpacing' => $plan->regionSpacing,
            'center' => $plan->center,
            'coreDirectory' => $plan->coreDirectory,
            'baseHostname' => $plan->baseHostname,
            'publicPort' => $plan->publicPort,
            'privatePort' => $plan->privatePort,
            'databaseHost' => $plan->dbHost,
            'databaseName' => $plan->dbName,
            'databaseUser' => $plan->dbUser,
            'databasePassword' => $plan->dbPass,
            'dataDirectory' => $plan->dataDirectory,
            'cacheDirectory' => $plan->cacheDirectory,
            'logsDirectory' => $plan->logsDirectory,
            // What its Robust serves: as written when it is, else as the setup will write it
            'connectors' => is_file($robustIni) ? Robust::connectorsIn($robustIni) : \OpenSim\Provisioning\RobustConfig::connectors($plan->helpers, $plan->enableHypergrid),
            'search' => $plan->search,
            'helpers' => $plan->helpers,
            'webUrl' => $plan->webUrl,
            'helpersPath' => $plan->helpersPath,
        ], [self::PLAN]);
    }

    /**
     * A grid from what is known of it: the answers of the setup about a grid run elsewhere, a description kept, a test.
     *
     * @param array<string,mixed> $values by the names of the properties (nick, name, slug, directory, baseHostname, publicPort,
     *        privatePort, hypergrid, preset, remote...); what is not given is what a grid is by the book
     */
    public static function described(array $values, ?Environment $environment = null): self
    {
        $environment ??= Environment::forProfile([]);

        return new self($environment, rtrim((string) ($values['directory'] ?? ''), '/'), $values, [self::DESCRIBED]);
    }

    /**
     * A grid from what it says of itself at its public port, null when it does not answer.
     */
    public static function announced(Environment $environment, string $host, int $port): ?self
    {
        $said = self::announcement($environment, $host, $port);
        if ($said === []) {
            return null;
        }

        return new self($environment, '', [
            'nick' => $said['gridnick'] ?? '',
            'name' => $said['gridname'] ?? '',
            'baseHostname' => $host,
            'publicPort' => $port,
            'announcement' => $said,
        ], [self::ANNOUNCED]);
    }

    /**
     * What a grid says of itself at its public port (get_grid_info), as it says it.
     *
     * @return array<string,string> gridname, gridnick, login... by the name of the element; empty when it does not answer
     */
    public static function announcement(Environment $environment, string $host, int $port): array
    {
        $url = "http://$host:$port/get_grid_info";
        $body = $environment->http !== null
            ? ($environment->http)($url)
            : @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
        $said = [];
        if (is_string($body) && preg_match_all('#<([A-Za-z_][\w.-]*)>([^<]+)</\1>#', $body, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as [, $key, $value]) {
                $said[$key] = trim(html_entity_decode($value, ENT_QUOTES | ENT_XML1));
            }
        }

        return $said;
    }

    /**
     * The same grid with these properties, by their names (nick, name, databaseHost, publicPort...).
     *
     * @param array<string,mixed> $values
     */
    public function with(array $values): self
    {
        return new self($this->environment, $this->directory, $values + $this->values(), $this->sources);
    }

    /** @return list<string> the sources that know it: folder, plan, described, announced */
    public function sources(): array
    {
        return $this->sources;
    }

    public function in(string $source): bool
    {
        return in_array($source, $this->sources, true);
    }

    /** What the grid said of itself when it was asked (get_grid_info), empty when it was not. */
    public function said(): array
    {
        return (array) ($this->values()['announcement'] ?? []);
    }

    /** Whether the grid is there: a Robust config, or the description of a remote grid. */
    public function isKnown(): bool
    {
        return !$this->in(self::FOLDER) || $this->robustIni() !== null || $this->isRemote();
    }

    /** One property, from the sources, else what a grid is by the book. */
    private function get(string $key): mixed
    {
        return $this->values()[$key] ?? self::DEFAULTS[$key];
    }

    /**
     * What the sources say. A grid of this machine reads its folder the first time it is asked.
     *
     * @return array<string,mixed>
     */
    private function values(): array
    {
        return $this->values ??= $this->fromFolder();
    }

    /** @return array<string,mixed> */
    private function fromFolder(): array
    {
        $layout = $this->environment->layout;
        $nick = basename($this->directory);
        $conf = $this->settings();
        $values = [
            'nick' => $nick,
            'directory' => $this->directory,
            'coreDirectory' => $conf['CoreDirectory'] ?? $layout->get('CoreDirectory'),
            'dataDirectory' => $conf['DataDirectory'] ?? $layout->dataRoot() . "/$nick",
            'cacheDirectory' => $conf['CacheDirectory'] ?? $layout->cacheRoot() . "/$nick",
            'logsDirectory' => $conf['LogsDirectory'] ?? $layout->logsRoot(),
        ];
        $robustIni = $this->robustFile();
        if ($robustIni === null) {
            if (!$this->hasRemoteDescription()) {
                return $values;
            }

            return $values + [
                'remote' => true,
                'name' => $conf['GridName'] ?? Slug::nameOf($nick),
                'slug' => $conf['slug'] ?? Slug::slug($conf['GridName'] ?? Slug::nameOf($nick)),
                'hypergrid' => !in_array(strtolower($conf['Hypergrid'] ?? 'true'), ['false', '0', 'no'], true),
                'preset' => $conf['Preset'] ?? '',
                'baseHostname' => $conf['BaseHostname'] ?? 'localhost',
                'publicPort' => (int) ($conf['PublicPort'] ?? 8002),
                'privatePort' => (int) ($conf['PrivatePort'] ?? 8003),
            ];
        }

        $robust = new Robust($this->environment, $robustIni, $nick);
        $name = $robust->gridName() ?? ($conf['GridName'] ?? Slug::nameOf($nick));

        return $values + [
            'robustIni' => $robustIni,
            'hypergrid' => str_contains(basename($robustIni), '.HG.'),
            'name' => $name,
            'slug' => $conf['slug'] ?? Slug::slug($name),
            'regionSpacing' => ctype_digit($conf['RegionSpacing'] ?? '') ? (int) $conf['RegionSpacing'] : 0,
            'center' => $conf['Center'] ?? '',
            'regionFlags' => $robust->regionFlags(),
            'connectors' => $robust->connectors(),
            'baseHostname' => $robust->baseHostname() ?? 'localhost',
            'publicPort' => $robust->publicPort() ?? 8002,
            'privatePort' => $robust->privatePort() ?? 8003,
            'databaseHost' => $robust->databaseHost() ?? 'localhost',
            'databaseName' => $robust->databaseName() ?? '',
            'databaseUser' => $robust->databaseUser() ?? 'opensim',
            'databasePassword' => $robust->databasePassword() ?? '',
        ];
    }

    public function nick(): string
    {
        return (string) $this->get('nick');
    }

    /** The name the grid gives itself. */
    public function name(): string
    {
        return (string) $this->get('name');
    }

    public function slug(): string
    {
        return (string) $this->get('slug');
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function isHypergrid(): bool
    {
        return (bool) $this->get('hypergrid');
    }

    /** Free blocks the regions leave between them, the rule of the grid (a grid only described here follows the default one). */
    public function regionSpacing(): int
    {
        return (int) $this->get('regionSpacing');
    }

    /** The center of the grid, "x,y", empty when it has none (the one its port gives). */
    public function center(): string
    {
        return (string) $this->get('center');
    }

    /** The center of the grid: the one it was given, else the one its port gives. */
    public function centerPlace(): array
    {
        return LocationFinder::parse($this->center()) ?? LocationFinder::first($this->publicPort());
    }

    /**
     * The roles the regions of the Robust config have (see Region::flagNames()); empty for a grid described only.
     *
     * @return list<string>
     */
    public function regionFlags(): array
    {
        return (array) $this->get('regionFlags');
    }

    public function coreDirectory(): string
    {
        return (string) $this->get('coreDirectory');
    }

    public function baseHostname(): string
    {
        return (string) $this->get('baseHostname');
    }

    public function publicPort(): int
    {
        return (int) $this->get('publicPort');
    }

    public function privatePort(): int
    {
        return (int) $this->get('privatePort');
    }

    public function databaseHost(): string
    {
        return (string) $this->get('databaseHost');
    }

    public function databaseName(): string
    {
        return (string) $this->get('databaseName');
    }

    public function databaseUser(): string
    {
        return (string) $this->get('databaseUser');
    }

    public function databasePassword(): string
    {
        return (string) $this->get('databasePassword');
    }

    public function dataDirectory(): string
    {
        return (string) $this->get('dataDirectory');
    }

    public function cacheDirectory(): string
    {
        return (string) $this->get('cacheDirectory');
    }

    public function logsDirectory(): string
    {
        return (string) $this->get('logsDirectory');
    }

    /** The preset of a remote grid (see Presets): the settings its simulators need, kept as an ini in its folder. */
    public function preset(): string
    {
        return (string) $this->get('preset');
    }

    /** A grid that is only planned, not made yet (the quick setup plans its first simulator before making anything). */
    public function isPlanned(): bool
    {
        return (bool) $this->get('planned');
    }

    /**
     * The connectors Robust serves (BakedTextureService, EstateDataService, GroupsServiceConnector...).
     *
     * @return list<string>
     */
    public function connectors(): array
    {
        return (array) $this->get('connectors');
    }

    /** Whether Robust serves a connector of [ServiceList] (the keys the distribution comments: see RobustConfig::connectors()). */
    public function serves(string $connector): bool
    {
        return in_array($connector, $this->connectors(), true);
    }

    /** The web site of the grid's helpers, when it has some (see helperUrl()). */
    public function webUrl(): string
    {
        return (string) $this->get('webUrl');
    }

    public function hasHelpers(): bool
    {
        return (bool) $this->get('helpers');
    }

    public function helpersPath(): string
    {
        return (string) $this->get('helpersPath');
    }

    /** Where the search of a grid that is planned is: internal (its helpers), shared (see SHARED_SEARCH) or disabled. */
    public function search(): string
    {
        return (string) $this->get('search');
    }

    /**
     * The address of a service of the helpers (offline, register, query...), empty when the grid has none: under the economy
     * the grid gives its viewers, on its web site.
     */
    public function helperUrl(string $service): string
    {
        if ($this->isPlanned()) {
            // The search of the simulators (query, register) is the helpers' own, another provider's or nobody's
            if (in_array($service, ['query', 'register'], true) && $this->search() !== 'internal') {
                return $this->search() === 'shared' ? self::SHARED_SEARCH . "/$service.php" : '';
            }

            return $this->hasHelpers() ? (new \OpenSim\Provisioning\Web\Services($this->helpersPath()))->url($this->webUrl(), $service) : '';
        }
        $webUrl = \OpenSim\Provisioning\Web\HelpersConfig::webUrl($this->directory);
        if ($webUrl === '') {
            return '';
        }

        return \OpenSim\Provisioning\Web\HelpersConfig::services($this->directory)->url($webUrl, $service);
    }

    /**
     * The roles a region of this grid can have: all of them, a grid can have several regions with the same role.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        if ($this->isRemote()) {
            return [];
        }
        $flags = [Region::DEFAULT_REGION, ...($this->isHypergrid() ? [Region::DEFAULT_HGREGION] : []), Region::FALLBACK_REGION];

        return array_map(Region::flagName(...), $flags);
    }

    /**
     * The roles no region of a grid run from this machine has yet (see roles()), to offer them to the next region.
     *
     * @return list<string>
     */
    public function missingRoles(): array
    {
        return $this->isRemote() ? [] : array_values(array_diff($this->roles(), $this->regionFlags()));
    }

    /**
     * Whether a grid run from this machine still needs its default region (the one visitors arrive in), which comes first: until
     * it exists nobody can log in.
     */
    public function needsLandingRegion(): bool
    {
        return array_intersect($this->missingRoles(), [Region::flagName(Region::DEFAULT_REGION), Region::flagName(Region::DEFAULT_HGREGION)]) !== [];
    }

    /** The description of a remote grid, to keep it: what a simulator needs to join it. */
    public function describe(): array
    {
        return [
            'nick' => $this->nick(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'baseHostname' => $this->baseHostname(),
            'publicPort' => $this->publicPort(),
            'privatePort' => $this->privatePort(),
            'hypergrid' => $this->isHypergrid(),
            'preset' => $this->preset(),
            'dataDirectory' => $this->dataDirectory(),
            'cacheDirectory' => $this->cacheDirectory(),
            'logsDirectory' => $this->logsDirectory(),
            'directory' => $this->directory(),
        ];
    }

    /**
     * Every fact about it, whatever the source, the password of its database left out.
     *
     * @return array<string,mixed>
     */
    public function detail(): array
    {
        $folder = $this->in(self::FOLDER);

        return [
            'nick' => $this->nick(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'sources' => $this->sources(),
            'directory' => $this->directory() ?: null,
            'remote' => $this->isRemote(),
            'planned' => $this->isPlanned(),
            'enabled' => $folder ? $this->isEnabled() : null,
            'hypergrid' => $this->isHypergrid(),
            'baseHostname' => $this->baseHostname(),
            'publicPort' => $this->publicPort(),
            'privatePort' => $this->privatePort(),
            'center' => $this->center() ?: null,
            'regionSpacing' => $this->regionSpacing(),
            'preset' => $this->preset() ?: null,
            'coreDirectory' => $this->coreDirectory() ?: null,
            'robustIni' => $this->robustIni(),
            'connectors' => $this->connectors(),
            'regionFlags' => $this->regionFlags(),
            'roles' => $this->roles(),
            'missingRoles' => $this->missingRoles(),
            'databaseHost' => $this->databaseHost(),
            'databaseName' => $this->databaseName(),
            'databaseUser' => $this->databaseUser(),
            'webUrl' => $this->webUrl() ?: null,
            'helpers' => $this->hasHelpers(),
            'simulators' => $folder ? $this->simulatorNames() : null,
            'announcement' => $this->said() ?: null,
        ];
    }

    /** The name of the instance running its Robust: what the launcher makes of the config file name. */
    public function robustInstance(): string
    {
        return Slug::slug($this->nick());
    }

    /**
     * A database of the grid, as OpenSimulator chooses it from the files of the grid: a Database, or the section of a service of
     * Robust (UserAccountService, GridService...), the setting of the service when it has one, else [DatabaseService]. A grid with no
     * files (planned, described) has the database it is given for all.
     *
     * @param ?string $simulator for the databases of a simulator
     */
    public function connection(Database|string $which = Database::Robust, ?string $simulator = null): ?ConnectionString
    {
        if ($this->in(self::FOLDER) && $this->directory !== '') {
            $config = GridConfig::fromFolder($this->directory);

            return $which instanceof Database ? $which->connection($config, $simulator) : Database::service($config, $which);
        }

        return $this->databaseName() !== ''
            ? ConnectionString::for($this->databaseHost(), $this->databaseName(), $this->databaseUser(), $this->databasePassword())
            : null;
    }

    /**
     * Every database the grid keeps data in, once each: the ones of its Robust (the default one, the services that have their own,
     * the assets, the profiles, the offline messages, the economy, the estates when Robust serves them) and the ones of its
     * simulators (their regions and their estates). The search is not the grid's: it is made again from the simulators.
     *
     * @param ?string $simulator only the databases of this simulator (the file name of its config), none of Robust's
     * @return list<ConnectionString>
     */
    public function connections(?string $simulator = null): array
    {
        $found = [];
        $add = static function (?ConnectionString $connection) use (&$found): void {
            $connection !== null && $connection->database !== '' && ($found[$connection->key()] ??= $connection);
        };
        $config = GridConfig::fromFolder($this->directory);
        if ($simulator === null && !$this->isRemote()) {
            foreach ([Database::Robust, Database::Assets, Database::Profiles, Database::Offline, Database::Economy] as $database) {
                $add($database->connection($config));
            }
            foreach ($config->tree()['Robust'] ?? [] as $section => $settings) {
                is_array($settings) && isset($settings['ConnectionString']) && $add(Database::service($config, (string) $section));
            }
            Database::centralEstates($config) && $add(Database::Estate->connection($config));
        }
        foreach ($simulator !== null ? [$simulator] : array_keys($config->tree()['sims'] ?? []) as $name) {
            foreach ([Database::Simulator, Database::Estate] as $database) {
                $add($database->connection($config, (string) $name));
            }
        }

        return array_values($found);
    }

    /**
     * The settings of a database of the grid, to ask questions of it: the database of a service (a Database, or the section of a
     * service of Robust), else the default one of the grid.
     */
    public function databasePlan(Database|string|null $which = null): GridPlan
    {
        $connection = $which === null ? null : $this->connection($which);
        $plan = new GridPlan();
        $plan->dbHost = $connection?->source ?? $this->databaseHost();
        $plan->dbName = $connection?->database ?? $this->databaseName();
        $plan->dbUser = $connection?->user ?? $this->databaseUser();
        $plan->dbPass = $connection?->password ?? $this->databasePassword();

        return $plan;
    }

    /**
     * The settings of its description, `[Grid]` of `<nick>.conf`.
     *
     * @return array<string,string>
     */
    public function settings(): array
    {
        return ConfigReader::load($this->directory . '/' . basename($this->directory) . '.conf')['Grid'] ?? [];
    }

    /** Whether its Robust is on another machine: the grid is only described here. */
    public function isRemote(): bool
    {
        return $this->in(self::FOLDER) ? $this->robustFile() === null && $this->hasRemoteDescription() : (bool) $this->get('remote');
    }

    /** Whether the description of its folder says its Robust is elsewhere. */
    private function hasRemoteDescription(): bool
    {
        return in_array(strtolower(trim($this->settings()['Remote'] ?? '')), ['true', '1', 'yes'], true);
    }

    /** The config file of its Robust in its folder. */
    private function robustFile(): ?string
    {
        foreach (self::ROBUST_FILES as $file) {
            if (is_file("{$this->directory}/$file")) {
                return "{$this->directory}/$file";
            }
        }

        return null;
    }

    /**
     * The config file of its Robust: the one of its folder, the one a plan will write. Null for a grid that has none (a remote grid).
     */
    public function robustIni(): ?string
    {
        if ($this->in(self::FOLDER)) {
            return $this->robustFile();
        }
        $ini = (string) $this->get('robustIni');

        return $ini !== '' ? $ini : null;
    }

    /** Its Robust on this machine, null for a remote grid. Run with the link that enables it when it is enabled. */
    public function robust(): ?Robust
    {
        $file = $this->robustIni();
        if ($file === null || !is_file($file)) {
            return null;
        }
        $robust = new Robust($this->environment, $file, $this->nick());

        return $robust->isEnabled() ? new Robust($this->environment, $robust->link(), $this->nick()) : $robust;
    }

    /**
     * Its simulators, enabled or not, by name.
     *
     * @return list<Simulator>
     */
    public function simulators(): array
    {
        $files = glob($this->directory . '/sims/*.ini') ?: [];
        sort($files);
        $simulators = [];
        foreach ($files as $file) {
            $simulator = new Simulator($this->environment, $file);
            $simulators[] = $simulator->isEnabled() ? new Simulator($this->environment, $simulator->link()) : $simulator;
        }

        return $simulators;
    }

    /**
     * The names of the config files of its simulators, enabled or not: what their links and their logs are named after.
     *
     * @return list<string>
     */
    public function simulatorNames(): array
    {
        $names = array_map(static fn(string $file): string => basename($file, '.ini'), glob($this->directory . '/sims/*.ini') ?: []);
        sort($names);

        return $names;
    }

    /** The link that enables a simulator, by the name of its config file: where the launcher finds it. */
    public function simulatorLink(string $name): string
    {
        return (new Simulator($this->environment, "{$this->directory}/sims/$name.ini"))->link();
    }

    /** @return list<Simulator> the simulators that start with the others */
    public function enabledSimulators(): array
    {
        return array_values(array_filter($this->simulators(), static fn(Simulator $s): bool => $s->isEnabled()));
    }

    /** One of its simulators by name. */
    public function simulator(string $name): ?Simulator
    {
        foreach ($this->simulators() as $simulator) {
            if ($simulator->name() === Instance::cleanName($name)) {
                return $simulator;
            }
        }

        return null;
    }

    /** Whether the grid is run from this machine: its Robust is enabled. */
    public function isEnabled(): bool
    {
        return $this->robust()?->isEnabled() ?? false;
    }

    /** Enable its Robust. */
    public function enable(): bool
    {
        return $this->robust()?->enable() ?? false;
    }

    public function disable(): void
    {
        $this->robust()?->disable();
    }

    /**
     * The instances to run, in the order they start: its Robust, then, when asked, its enabled simulators.
     *
     * @return list<Instance>
     */
    public function instances(bool $simulators = false): array
    {
        $robust = $this->robust();

        return [...($robust !== null && $robust->isEnabled() ? [$robust] : []), ...($simulators ? $this->enabledSimulators() : [])];
    }

    /**
     * Start the services of the grid, then its simulators when asked. A simulator is not started when Robust did not come up.
     *
     * @return list<Result>
     */
    public function start(bool $simulators = false): array
    {
        $results = [];
        foreach ($this->instances($simulators) as $instance) {
            $result = $instance->start();
            $results[] = $result;
            if ($instance instanceof Robust && !$result->ok()) {
                break;
            }
        }

        return $results;
    }

    /**
     * Stop the services of the grid, its simulators first when asked.
     *
     * @return list<Result>
     */
    public function stop(bool $now = false, bool $simulators = false): array
    {
        return array_map(static fn(Instance $i): Result => $i->stop($now), array_reverse($this->instances($simulators)));
    }

    /**
     * Restart the services of the grid, and its simulators when asked: everything stops, then everything starts.
     *
     * @return list<Result>
     */
    public function restart(bool $now = false, bool $simulators = false): array
    {
        return [...$this->stop($now, $simulators), ...$this->start($simulators)];
    }
}
