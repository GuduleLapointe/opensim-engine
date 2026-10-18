<?php

declare(strict_types=1);

namespace OpenSim\Data;

use OpenSim\Config\ConnectionString;
use OpenSim\Config\GridConfig;

/**
 * The databases of a grid the engine knows: where each is set, in which order OpenSimulator looks for it, and what it holds.
 *
 *     Database::Assets->connection($config)               // the ConnectionString of the assets of the grid
 *     Database::Estate->connection($config, 'alpha')      // the one of the estates of the simulator alpha
 *     Database::Search->pdo($config)                      // an OSPDO on the search database
 *
 * A setting is written `Role.Section.Key`: the role is a file of the grid folder (Robust, OpenSim, MoneyServer, Helpers), and
 * `Simulator` stands for the file of the simulator, then the OpenSim.ini of the grid. `Robust` is the Robust
 * config, else the reduced copy of it in Helpers.ini (a web host), else the OpenSim.ini of a standalone grid. The settings are listed in
 * the order OpenSimulator reads them, the first that has a value is the database; the last of the list is the database the
 * others fall back to.
 *
 * Only MySQL and MariaDB are handled for now: another provider has no connection string to parse.
 */
enum Database: string
{
    /** The services of the grid: accounts, authentication, avatars, friends, groups, grid, inventory, presence... and its regions table. */
    case Robust = 'robust';
    /** The assets of the grid, in the database of Robust unless the asset service has its own. */
    case Assets = 'assets';
    /** The profiles of the avatars (picks, notes, classifieds, settings). */
    case Profiles = 'profiles';
    /** The instant messages kept for the users who are not there (OfflineIMService of Robust, or the module of the simulators). */
    case Offline = 'offline';
    /** What a simulator keeps about its regions: settings, parcels (`land`), terrain, objects. */
    case Simulator = 'simulator';
    /** The estates: the grid's when its Robust serves them (EstateDataService), else each simulator's. */
    case Estate = 'estate';
    /** The economy: the MoneyServer, or the storage of the currency module of the simulators. */
    case Economy = 'economy';
    /** The search of the web and the in-world search (OpenSimSearch), made by the helpers; not used by OpenSimulator itself. */
    case Search = 'search';

    /** The database is the grid's. */
    public const GRID = 'grid';

    /** The database is a simulator's. */
    public const PER_SIMULATOR = 'simulator';

    /**
     * Where the estate service of Robust has its database, in the order the core reads them (EstateDataService): the last that
     * says one wins in the core, the first in this list.
     */
    private const CENTRAL_ESTATE = [
        'Robust.EstateDataStore.ConnectionString',
        'Robust.DatabaseService.EstateConnectionString',
        'Robust.DatabaseService.ConnectionString',
    ];

    /**
     * The services of Robust whose database is not set in `ConnectionString` of their own section alone, by section, in the order
     * the core reads them (the last that says one wins in the core, the first in this list).
     */
    private const SERVICE_SETTINGS = [
        'MuteListStore' => ['Robust.MuteListStore.ConnectionString', 'Robust.DatabaseService.MuteConnectionString'],
    ];

    /** What the MoneyServer.ini of the distribution says for the database, until it is edited. */
    private const MONEY_PLACEHOLDERS = ['Database_name', 'Database_user', 'Database_password'];

    public function label(): string
    {
        return match ($this) {
            self::Robust => _('Grid services'),
            self::Assets => _('Assets'),
            self::Profiles => _('Profiles'),
            self::Offline => _('Offline messages'),
            self::Simulator => _('Simulator'),
            self::Estate => _('Estates'),
            self::Economy => _('Economy'),
            self::Search => _('Search'),
        };
    }

    /** Whether there is one for the grid (self::GRID) or one for each simulator (self::PER_SIMULATOR). */
    public function scope(): string
    {
        return match ($this) {
            self::Simulator, self::Estate => self::PER_SIMULATOR,
            default => self::GRID,
        };
    }

    /**
     * Where its connection is set, in the order it is looked for. For the economy, the settings are those of the module that
     * the grid uses (MoneyServer, else Gloebit, else the default of the simulator); the MoneyServer's are the parts of a
     * connection (`hostname`, `database`, `username`, `password`, `port` of [MySql]).
     *
     * @return list<string> Role.Section.Key
     */
    public function settings(): array
    {
        return match ($this) {
            self::Robust => ['Robust.DatabaseService.ConnectionString'],
            self::Assets => ['Robust.AssetService.ConnectionString', 'Robust.DatabaseService.ConnectionString'],
            self::Profiles => ['Robust.UserProfilesService.ConnectionString', 'Robust.DatabaseService.ConnectionString'],
            self::Offline => ['Robust.Messaging.ConnectionString', 'Robust.DatabaseService.ConnectionString'],
            self::Simulator => ['Simulator.SimulationDataStore.ConnectionString', 'Simulator.DatabaseService.ConnectionString'],
            self::Estate => [
                'Simulator.EstateDataStore.ConnectionString',
                'Simulator.DatabaseService.EstateConnectionString',
                'Simulator.DatabaseService.ConnectionString',
            ],
            self::Economy => [
                'MoneyServer.MySql.database',
                'Simulator.Gloebit.GLBSpecificConnectionString',
                'Simulator.DatabaseService.ConnectionString',
            ],
            self::Search => ['Helpers.Helpers.SearchConnectionString', 'Robust.DatabaseService.ConnectionString'],
        };
    }

    /**
     * The tables the engine knows in it, when the database is not shared with others (a shared database has them all).
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return match ($this) {
            self::Robust => [
                'UserAccounts', 'GridUser', 'Presence', 'auth', 'tokens', 'regions', 'Avatars', 'AgentPrefs', 'Friends', 'MuteList',
                'inventoryfolders', 'inventoryitems', 'hg_traveling_data', 'os_groups_groups', 'os_groups_membership',
                'os_groups_roles', 'os_groups_rolemembership', 'os_groups_principals', 'os_groups_notices', 'os_groups_invites',
            ],
            self::Assets => ['assets', 'fsassets', 'XAssetsMeta', 'XAssetsData'],
            self::Profiles => ['userprofile', 'userdata', 'usernotes', 'userpicks', 'usersettings', 'classifieds'],
            self::Offline => ['im_offline'],
            self::Simulator => [
                'regionsettings', 'land', 'landaccesslist', 'prims', 'primshapes', 'primitems', 'terrain', 'bakedterrain',
                'regionban', 'regionenvironment', 'regionextra', 'regionwindlight', 'spawn_points',
            ],
            self::Estate => ['estate_settings', 'estate_map', 'estate_managers', 'estate_groups', 'estate_users', 'estateban'],
            self::Economy => ['balances', 'transactions', 'totalsales', 'userinfo'],
            self::Search => [
                'parcels', 'allparcels', 'regions', 'regionsregister', 'objects', 'popularplaces', 'events', 'classifieds', 'hostsregister', 'parcelsales',
                'oshelpers_cache',
            ],
        };
    }

    /**
     * The connection to it, null when the grid does not say it (no such file, no such setting, no database to connect to).
     *
     * @param ?string $simulator the file name of the simulator (without .ini), for the databases of a simulator
     */
    public function connection(GridConfig $config, ?string $simulator = null): ?ConnectionString
    {
        if ($this === self::Estate && self::centralEstates($config)) {
            // The estate service of Robust reads the settings the simulators read, in Robust's config
            foreach (self::CENTRAL_ESTATE as $path) {
                $value = self::read($config, $path, null);
                if ($value !== null) {
                    return ConnectionString::parse($value);
                }
            }

            return null;
        }
        if ($this->scope() === self::PER_SIMULATOR && $simulator === null) {
            return null;
        }
        if ($this === self::Economy) {
            return $this->economy($config, $simulator);
        }
        foreach ($this->settings() as $path) {
            $value = self::read($config, $path, $simulator);
            if ($value !== null) {
                return ConnectionString::parse($value);
            }
        }

        return null;
    }

    /** Whether the estates are the grid's: its Robust serves the estate service. */
    public static function centralEstates(GridConfig $config): bool
    {
        return trim((string) ($config->tree()['Robust']['ServiceList']['EstateDataService'] ?? '')) !== '';
    }

    /** The scope of the database in a grid: the estates are the grid's when its Robust serves them. */
    public function scopeIn(GridConfig $config): string
    {
        return $this === self::Estate && self::centralEstates($config) ? self::GRID : $this->scope();
    }

    /**
     * An OSPDO on it, null when the grid does not say it. The connection may have failed: check `connected`.
     *
     * @param ?string $simulator see connection()
     */
    public function pdo(GridConfig $config, ?string $simulator = null): ?OSPDO
    {
        $connection = $this->connection($config, $simulator);

        return $connection === null ? null : new OSPDO($connection->credentials());
    }

    /**
     * The database of a service of Robust, the way OpenSimulator chooses it: the one the service has in its section, else the one
     * of [DatabaseService].
     *
     * @param string $section UserAccountService, GridUserService, InventoryService...
     */
    public static function service(GridConfig $config, string $section): ?ConnectionString
    {
        foreach (self::serviceSettings($section) as $path) {
            $value = self::read($config, $path, null);
            if ($value !== null) {
                return ConnectionString::parse($value);
            }
        }

        return null;
    }

    /**
     * Where the database of a service of Robust is set, in the order it is looked for: the section of the service, then
     * [DatabaseService].
     *
     * @return list<string> Role.Section.Key
     */
    public static function serviceSettings(string $section): array
    {
        return [...(self::SERVICE_SETTINGS[$section] ?? ["Robust.$section.ConnectionString"]), 'Robust.DatabaseService.ConnectionString'];
    }

    /**
     * The database of a service as the config of one Robust says it (the sections and keys of the files it reads).
     *
     * @param array<string,array<string,string>> $settings section => key => value
     */
    public static function serviceOf(array $settings, string $section): ?ConnectionString
    {
        return self::firstIn($settings, self::serviceSettings($section));
    }

    /**
     * The database as the config of one instance says it: the settings of its files, a Robust or a simulator. A Robust that
     * serves the estates reads them where the simulators do.
     *
     * @param array<string,array<string,string>> $settings section => key => value
     */
    public function connectionOf(array $settings, bool $robust = false): ?ConnectionString
    {
        $paths = $this === self::Estate && $robust ? self::CENTRAL_ESTATE : $this->settings();

        return self::firstIn($settings, $paths);
    }

    /**
     * The database a table is in, for what Robust and its modules keep: a Database, or the section of the service of Robust that
     * owns the table. Null for a table the engine does not know there.
     */
    public static function forTable(string $table): self|string|null
    {
        $table = strtolower($table);
        foreach ([self::Assets, self::Profiles, self::Offline] as $database) {
            if (in_array($table, array_map('strtolower', $database->tables()), true)) {
                return $database;
            }
        }

        return match (true) {
            $table === 'useraccounts' => 'UserAccountService',
            $table === 'griduser' => 'GridUserService',
            $table === 'presence' => 'PresenceService',
            $table === 'auth', $table === 'tokens' => 'AuthenticationService',
            $table === 'avatars' => 'AvatarService',
            $table === 'agentprefs' => 'AgentPreferencesService',
            $table === 'friends' => 'FriendsService',
            $table === 'mutelist' => 'MuteListStore',
            $table === 'inventoryfolders', $table === 'inventoryitems' => 'InventoryService',
            $table === 'regions' => 'GridService',
            $table === 'hg_traveling_data' => 'UserAgentService',
            str_starts_with($table, 'os_groups_') => 'Groups',
            default => null,
        };
    }

    /**
     * The first of these settings that has a value.
     *
     * @param array<string,array<string,string>> $settings
     * @param list<string> $paths Role.Section.Key
     */
    private static function firstIn(array $settings, array $paths): ?ConnectionString
    {
        foreach ($paths as $path) {
            [, $section, $key] = explode('.', $path, 3);
            $value = trim((string) ($settings[$section][$key] ?? ''));
            if ($value !== '') {
                return ConnectionString::parse($value);
            }
        }

        return null;
    }

    /**
     * Every database of the grid, with its connection (null when the grid does not say it): the ones of the grid once, the ones of
     * a simulator for each of its simulators.
     *
     * @return list<array{database:self,simulator:?string,connection:?ConnectionString}>
     */
    public static function known(GridConfig $config): array
    {
        $simulators = array_keys($config->tree()['simulators'] ?? []);
        $known = [];
        foreach (self::cases() as $database) {
            foreach ($database->scopeIn($config) === self::GRID ? [null] : $simulators as $simulator) {
                $known[] = [
                    'database' => $database,
                    'simulator' => $simulator,
                    'connection' => $database->connection($config, $simulator),
                ];
            }
        }

        return $known;
    }

    /** The value of a setting; null when it is not there. A simulator reads its own file, then the OpenSim.ini of the grid. */
    private static function read(GridConfig $config, string $path, ?string $simulator): ?string
    {
        [$role, $section, $key] = explode('.', $path, 3);
        $tree = $config->tree();
        if ($role === 'Simulator') {
            $own = $tree['simulators'][$simulator] ?? [];
            $value = $own[$section][$key] ?? ($tree['OpenSim'][$section][$key] ?? null);
        } elseif ($role === 'Robust') {
            // The services are in the Robust config; a web host has a reduced copy of it in Helpers.ini, a standalone simulator has
            // them in its OpenSim.ini
            $value = $tree['Robust'][$section][$key] ?? ($tree['Helpers'][$section][$key] ?? ($tree['OpenSim'][$section][$key] ?? null));
        } else {
            $value = $tree[$role][$section][$key] ?? null;
        }
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * The economy of the module the grid uses: [Economy] economymodule says which. BetaGridLikeMoneyModule, the default of
     * OpenSimulator, has no database.
     */
    private function economy(GridConfig $config, ?string $simulator): ?ConnectionString
    {
        $module = strtolower(trim((string) ($config->find('Economy', 'economymodule') ?? '')));
        if ($module === '' || $module === 'betagridlikemoneymodule') {
            return null;
        }
        if (str_contains($module, 'gloebit')) {
            // Its own storage, else the default one of the simulator
            foreach (array_slice($this->settings(), 1) as $path) {
                $value = self::read($config, $path, $simulator);
                if ($value !== null) {
                    return ConnectionString::parse($value);
                }
            }

            return null;
        }

        // The MoneyServer reads its own file, a [MySql] section of separate parts
        $money = $config->tree()['MoneyServer']['MySql'] ?? [];
        $database = trim((string) ($money['database'] ?? ''));
        if ($database === '' || in_array($database, self::MONEY_PLACEHOLDERS, true)) {
            return null;
        }
        $port = trim((string) ($money['port'] ?? ''));
        $host = trim((string) ($money['hostname'] ?? '')) ?: 'localhost';

        return ConnectionString::for(
            $port !== '' && $port !== '3306' ? "$host:$port" : $host,
            $database,
            trim((string) ($money['username'] ?? '')),
            (string) ($money['password'] ?? ''),
        );
    }
}
