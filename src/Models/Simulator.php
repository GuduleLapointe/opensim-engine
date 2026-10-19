<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Provisioning\NameRule;
use OpenSim\Config\ConnectionString;
use OpenSim\Console\ConsoleInterface;
use OpenSim\Controllers\OpenSimController;
use OpenSim\Data\Collection;
use OpenSim\Provisioning\Slug;
use OpenSim\Runtime\Core;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\LogFile;
use OpenSim\Runtime\Process;
use OpenSim\Runtime\Result;

/**
 * A simulator: what runs one or several regions of a grid, with the config it is made of (its `<name>.ini`). Its identity, its
 * regions, its estate and its database are here; the program that runs it, started, stopped and talked to, is its controller
 * (OpenSimController), which it starts and stops for whoever asks.
 *
 * Its services are the ones of its grid, as its config says them (see Service).
 */
final class Simulator extends InstalledModel
{
    private readonly OpenSimController $controller;

    public function __construct(Environment $environment, string $ini, ?string $name = null)
    {
        parent::__construct($environment);
        $this->controller = new OpenSimController($environment, $ini, $name);
    }

    /** What runs it. */
    public function controller(): OpenSimController
    {
        return $this->controller;
    }

    /**
     * The fields of a simulator, in the standard format of the forms (see Forms\FieldSet): what the setup asks to make or to change
     * one, and what any other form about a simulator shows. The groups are the screens of the setup.
     *
     * @param array{cores?:array<string,string>,modify?:bool,accounts?:?list<string>} $options the cores to run it (folder => name; with
     *        one there is no question), whether the simulator exists (its name stays), and the accounts of its grid that can own its
     *        estate (null: they are not known here, the owner is typed and has to exist; none: the owner is the first to create)
     * @return list<array<string,mixed>>
     */
    public static function fields(array $options = []): array
    {
        $cores = $options['cores'] ?? [];
        $accounts = $options['accounts'] ?? null;
        $fresh = static fn(array $field): array => $field + ['required' => true, 'type' => 'string'];
        // The simulator of a standalone is the grid: it has its port and its database, which are not asked again
        $own = ($options['standalone'] ?? false) ? ['enable' => false] : [];

        $fields = [
            $fresh([
                'name' => 'name',
                'group' => 'simulator',
                'label' => _('Simulator name'),
                'callback' => [self::class, 'validateName'],
                'enable' => !($options['modify'] ?? false),
            ]),
            [
                'name' => 'core',
                'group' => 'simulator',
                'type' => 'select',
                'label' => _('OpenSim core to run this simulator'),
                'options' => $cores,
                'enable' => count($cores) > 1,
            ],
            $fresh([
                'name' => 'http_port',
                'group' => 'simulator',
                'type' => 'integer',
                'label' => _('Simulator HTTP port'),
                'callback' => [Grid::class, 'validatePort'],
            ] + $own),
            $fresh([
                'name' => 'external_host',
                'group' => 'simulator',
                'label' => _('Public address of this machine'),
                'description' => _('What the regions announce to the viewers (SYSTEMIP: the first interface)'),
            ]),
            [
                'name' => 'console',
                'group' => 'simulator',
                'type' => 'select',
                'label' => _('Console of the simulator'),
                'options' => [
                    'rest' => _('Remote REST console (recommended)'),
                    'screen' => _('Screen session (on this machine)'),
                ],
            ],
            $fresh([
                'name' => 'console_port',
                'follows' => ['http_port'],
                'group' => 'simulator',
                'type' => 'integer',
                'label' => _('Console port'),
                'callback' => [Grid::class, 'validatePort'],
                'condition' => ['console' => 'rest'],
            ]),
            $fresh(['name' => 'database_host', 'group' => 'database', 'label' => _('Database host')] + $own),
            $fresh(['name' => 'database_name', 'group' => 'database', 'label' => _('Database name')] + $own),
            $fresh(['name' => 'database_user', 'group' => 'database', 'label' => _('Database user')] + $own),
            $fresh(['name' => 'database_password', 'group' => 'database', 'label' => _('Database password')] + $own),
            $fresh(['name' => 'estate_name', 'group' => 'estate', 'label' => _('Estate name')]),
        ];

        // The owner of the estate is an account of the grid, which OpenSimulator cannot make: it is chosen among the grid's, typed
        // when they are not known here, or made first
        if ($accounts === null) {
            $fields[] = $fresh([
                'name' => 'estate_owner',
                'group' => 'estate',
                'label' => _('Estate owner (an account of the grid)'),
                'callback' => [Avatar::class, 'validateName'],
            ]);
        } elseif ($accounts !== []) {
            $fields[] = [
                'name' => 'estate_owner',
                'group' => 'estate',
                'type' => 'select',
                'label' => _('Estate owner'),
                'options' => array_combine($accounts, $accounts) + ['+' => _('Create a new account in the grid')],
            ];
        }
        $new =
            $accounts === null
                ? ['enable' => false]
                : ($accounts === []
                    ? []
                    : ['condition' => ['estate_owner' => '+']]);
        $fields[] = $fresh(
            [
                'name' => 'new_name',
                'group' => 'estate',
                'label' => _('Name of the new account (First Last)'),
                'callback' => [Avatar::class, 'validateNewName'],
                'normalize' => [Avatar::class, 'normalizeName'],
            ] + $new,
        );
        $fields[] = $fresh(
            [
                'name' => 'new_password',
                'group' => 'estate',
                'type' => 'secret',
                'label' => _('Password of the new account'),
                'callback' => [Avatar::class, 'validatePassword'],
            ] + $new,
        );
        $fields[] =
            [
                // OpenSimulator does not need one, no more than the setup
                'name' => 'new_email',
                'group' => 'estate',
                'type' => 'string',
                'required' => false,
                'label' => _('Email of the new account (optional)'),
                'callback' => [Avatar::class, 'validateEmail'],
            ] + $new;

        return $fields;
    }

    /** The name of a simulator. An accent is not refused, it is transliterated (Noël becomes Noel). */
    public static function validateName(mixed $value): ?string
    {
        return (new NameRule('-'))->problem(Slug::ascii((string) $value));
    }





    /**
     * Where it is reached, `host:port`: what identifies it. The port is the one it listens on, the host the one its regions announce
     * (this machine's own when they say SYSTEMIP or when it has no region yet). Null when its config does not say its port.
     */
    public function uri(): ?string
    {
        $port = $this->httpPort();
        if ($port === null) {
            return null;
        }
        $host = '';
        foreach ($this->regionsDirectory() !== '' ? Region::inDirectory($this->regionsDirectory()) : [] as $region) {
            $host = (string) $region->externalHost();
            if ($host !== '') {
                break;
            }
        }
        if ($host === '' || strcasecmp($host, 'SYSTEMIP') === 0) {
            $host = (string) gethostname();
        }

        return $host !== '' ? "$host:$port" : null;
    }

    /**
     * The simulators of the machine, those of every grid.
     *
     * Options: `search` (words of the slug, the address or the grid), `sort` ("name", "port", "grid"), `filters` (`grid`: a nick;
     * `enabled`, `running`: yes or no), `page` and `perPage`.
     */
    public static function all(Environment $environment, array $options = []): Collection
    {
        $simulators = [];
        foreach (Grid::all($environment)->items() as $grid) {
            array_push($simulators, ...$grid->simulators());
        }
        $yesNo = static fn(\Closure $is): array => [
            static fn(self $simulator, string $value): bool => $is($simulator) === ($value === 'yes'),
            ['yes', 'no'],
        ];

        return self::listing(
            $simulators,
            $options,
            static fn(
                self $simulator,
            ): string => "{$simulator->slug()} {$simulator->uri()} {$simulator->grid()?->name()}",
            [
                'name' => static fn(self $simulator): string => $simulator->slug(),
                'port' => static fn(self $simulator): int => (int) $simulator->httpPort(),
                'grid' => static fn(self $simulator): string => (string) $simulator->grid()?->nick(),
            ],
            [
                'grid' => [static fn(self $simulator, string $nick): bool => $simulator->grid()?->nick() === $nick, []],
                'enabled' => $yesNo(static fn(self $simulator): bool => $simulator->isEnabled()),
                'running' => $yesNo(static fn(self $simulator): bool => $simulator->isRunning()),
            ],
            [
                ['name' => 'name', 'label' => _('Name'), 'sortable' => true],
                ['name' => 'grid', 'label' => _('Grid'), 'sortable' => true],
                ['name' => 'uri', 'label' => _('Address')],
                ['name' => 'port', 'label' => _('Port'), 'sortable' => true],
                ['name' => 'enabled', 'label' => _('Enabled')],
                ['name' => 'running', 'label' => _('Running')],
            ],
        );
    }

    /** A simulator by its address (`host:port`) or its slug (`vonda_welcome`). */
    public static function find(Environment $environment, string $id): ?static
    {
        foreach (self::all($environment)->items() as $simulator) {
            if (strcasecmp((string) $simulator->uri(), $id) === 0 || strcasecmp($simulator->slug(), $id) === 0) {
                return $simulator;
            }
        }

        return null;
    }

    /** What identifies it: its address, else its name. */
    public function id(): string
    {
        return $this->uri() ?? $this->name();
    }

    /**
     * Every fact about it, the password of its database and of its console left out.
     *
     * @return array<string,mixed>
     */
    public function detail(): array
    {
        return [
            'id' => $this->id(),
            'uri' => $this->uri(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'grid' => $this->grid()?->nick(),
            'ini' => $this->ini(),
            'link' => $this->link(),
            'enabled' => $this->isEnabled(),
            'running' => $this->isRunning(),
            'httpPort' => $this->httpPort(),
            'consolePort' => $this->consolePort(),
            'console' => $this->remoteConsole() !== null ? 'rest' : 'screen',
            'databaseHost' => $this->databaseHost(),
            'databaseName' => $this->databaseName(),
            'databaseUser' => $this->databaseUser(),
            'estateDatabaseName' => $this->estateDatabase()?->database,
            'estateName' => $this->estateName(),
            'estateOwner' => $this->estateOwner(),
            'regionsDirectory' => $this->regionsDirectory() ?: null,
            'core' => $this->core()?->directory(),
        ];
    }

    public function name(): string
    {
        return $this->controller->name();
    }

    /** The name for a path or a command: its own, which has its grid in it (`vonda_welcome`, not `welcome`: every grid has one). */
    public function slug(): string
    {
        return $this->controller->name();
    }

    /** The config file it runs with: its link when it is enabled. */
    public function ini(): string
    {
        return $this->controller->ini();
    }

    public function link(): string
    {
        return $this->controller->link();
    }

    /** Whether its config is there. */
    public function exists(): bool
    {
        return $this->controller->exists();
    }

    public function isEnabled(): bool
    {
        return $this->controller->isEnabled();
    }

    public function enable(): bool
    {
        return $this->controller->enable();
    }

    public function disable(): void
    {
        $this->controller->disable();
    }

    public function channels(): array
    {
        return $this->controller->canBeEnabled() ? ['ini'] : [];
    }

    public function start(): Result
    {
        return $this->controller->start();
    }

    public function stop(bool $now = false): Result
    {
        return $this->controller->stop($now);
    }

    public function restart(bool $now = false): Result
    {
        return $this->controller->restart($now);
    }

    public function isRunning(): bool
    {
        return $this->controller->isRunning();
    }

    public function process(): ?Process
    {
        return $this->controller->process();
    }

    public function console(): ConsoleInterface
    {
        return $this->controller->console();
    }

    public function logFile(): LogFile
    {
        return $this->controller->logFile();
    }

    /** The ports it listens on. */
    public function ports(): array
    {
        return $this->controller->ports();
    }

    /** The core it runs from. */
    public function core(): ?Core
    {
        return $this->controller->core();
    }

    /** @return ?array{port:int,user:string,password:string} its remote console, null when it runs in a screen session */
    public function remoteConsole(): ?array
    {
        return $this->controller->remoteConsole();
    }

    /** One setting of its config, as written, empty when it does not say it. */
    public function setting(string $section, string $key): string
    {
        return $this->controller->setting($section, $key);
    }

    /**
     * The settings of its config, the files it includes read the way OpenSimulator reads them.
     *
     * @return array<string,array<string,string>>
     */
    public function settings(): array
    {
        return $this->controller->settings();
    }

    public function launchSetting(string ...$names): string
    {
        return $this->controller->launchSetting(...$names);
    }

    /** The grid it belongs to, null for a config that is not in the folder of one. */
    public function grid(): ?Grid
    {
        return $this->controller->grid();
    }

    /**
     * The services the simulator uses, as it reaches them: its grid's, each at the address its own config says when it says one
     * (see Service::of() for the options).
     */
    public function services(array $options = []): Collection
    {
        return Service::of($this->grid(), $this, $options);
    }

    /** A service as the simulator reaches it (`$simulator->service('profile')->uri()`), null when it has none. */
    public function service(string $key): ?Service
    {
        $grid = $this->grid();
        if ($grid === null || !in_array($key, Service::keys(), true)) {
            return null;
        }
        $service = new Service($this->environment, $grid, $key, $this);

        return $service->exists() ? $service : null;
    }

    public function gridDirectory(): ?string
    {
        return $this->controller->gridDirectory();
    }

    /** The master of its grid, made again when the core or the grid changed. */
    public function master(string $binDir): ?string
    {
        return $this->controller->master($binDir);
    }

    /** A database it connects to, as OpenSimulator chooses it from its config (see Controller::connection()). */
    public function connection(\OpenSim\Data\Database|string $which): ?ConnectionString
    {
        return $this->controller->connection($which);
    }

    /** The database of its regions: [SimulationDataStore], else [DatabaseService]. */
    public function database(): ?ConnectionString
    {
        return $this->controller->database();
    }

    /** The database of its estates: [EstateDataStore], else EstateConnectionString of [DatabaseService], else the database of its regions. */
    public function estateDatabase(): ?ConnectionString
    {
        return $this->controller->estateDatabase();
    }

    public function databaseHost(): ?string
    {
        return $this->controller->databaseHost();
    }

    public function databaseName(): ?string
    {
        return $this->controller->databaseName();
    }

    public function databaseUser(): ?string
    {
        return $this->controller->databaseUser();
    }

    public function databasePassword(): ?string
    {
        return $this->controller->databasePassword();
    }

    public function consolePort(): ?int
    {
        return $this->controller->consolePort();
    }

    public function consoleHost(): ?string
    {
        return $this->controller->consoleHost();
    }

    public function consoleUser(): ?string
    {
        return $this->controller->consoleUser();
    }

    public function consolePassword(): ?string
    {
        return $this->controller->consolePassword();
    }

    /** The port it listens on, null when its config does not say it. */
    public function httpPort(): ?int
    {
        return $this->controller->httpPort();
    }

    public function estateName(): ?string
    {
        return $this->controller->estateName();
    }

    public function estateOwner(): ?string
    {
        return $this->controller->estateOwner();
    }

    /** The folder of the descriptions of its regions, empty when its config does not say where they are. */
    public function regionsDirectory(): string
    {
        return $this->controller->regionsDirectory();
    }

    /** Whether it has a region to load: a region file in its regions folder. One without waits for the answers of a region to create. */
    public function hasRegion(): bool
    {
        return $this->controller->hasRegion();
    }
}
