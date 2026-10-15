<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridConfig;
use OpenSim\Config\IniEditor;
use OpenSim\Data\Collection;
use OpenSim\Data\Connections;
use OpenSim\Provisioning\RegionName;
use OpenSim\Provisioning\Slug;

/**
 * A region: a square of the map of a grid, described by a file of the simulator that runs it (name, UUID, place, size, port) and
 * registered in the regions table of the grid services (its flags, its place, when it was last seen).
 *
 * The fields of a region are defined here, for whoever shows them: the setup of a grid, its administration, the page of the owner
 * of a region (see Forms\FieldSet).
 *
 * The flags are those of the regions table, named as in OpenSimulator (opensimulator.org/wiki/Regions_(database_table)). The ones a
 * region can be given are its roles; in the config of Robust they are written by name (see roles() and flagNames()).
 */
final class Region extends Model
{
    public const DEFAULT_REGION = 1;
    public const FALLBACK_REGION = 2;
    public const REGION_ONLINE = 4;
    public const NO_DIRECT_LOGIN = 8;
    public const PERSISTENT = 16;
    public const LOCKED_OUT = 32;
    public const NO_MOVE = 64;
    public const RESERVATION = 128;
    public const AUTHENTICATE = 256;
    public const HYPERLINK = 512;
    public const DEFAULT_HGREGION = 1024;

    /** The flags a region can be given in the config of Robust (Region_<Name> = "DefaultRegion, Persistent"), by their name there. */
    private const NAMES = [
        self::DEFAULT_REGION => 'DefaultRegion',
        self::DEFAULT_HGREGION => 'DefaultHGRegion',
        self::FALLBACK_REGION => 'FallbackRegion',
        self::NO_DIRECT_LOGIN => 'NoDirectLogin',
        self::PERSISTENT => 'Persistent',
        self::LOCKED_OUT => 'LockedOut',
        self::RESERVATION => 'Reservation',
        self::NO_MOVE => 'NoMove',
        self::AUTHENTICATE => 'Authenticate',
    ];

    /** The size of a region that does not say it. */
    private const DEFAULT_SIZE = 256;

    private const COLUMNS = 'r.uuid, r.regionName, r.regionHandle, r.locX, r.locY, r.sizeX, r.sizeY, r.serverIP, r.serverPort, r.serverURI, r.flags, r.last_seen, r.PrincipalID, r.access, r.ScopeID, r.regionMapTexture';

    /** The columns a list can be sorted on, by their name in the collection: the expressions they are sorted by. */
    private const SORTS = [
        'name' => ['r.regionName'],
        'location' => ['r.locX', 'r.locY'],
        'size' => ['r.sizeX', 'r.sizeY'],
        'lastSeen' => ['r.last_seen'],
        'flags' => ['r.flags'],
    ];

    public function id(): string
    {
        return (string) $this->row['uuid'];
    }

    public function uuid(): string
    {
        return $this->id();
    }

    public function name(): string
    {
        return (string) $this->row['regionName'];
    }

    public function slug(): string
    {
        return str_replace('_', '-', Slug::slug($this->name()));
    }

    /** Where it is reached from other grids: host:port:Region Name. */
    public function uri(): ?string
    {
        $grid = $this->config->gridUri();

        return $grid === null ? null : $grid . ':' . $this->name();
    }

    /** The owner of the region, the user the grid services know as its principal. */
    public function owner(): ?string
    {
        $owner = (string) ($this->row['PrincipalID'] ?? '');

        return $owner !== '' && $owner !== '00000000-0000-0000-0000-000000000000' ? $owner : null;
    }

    public function flags(): int
    {
        return (int) ($this->row['flags'] ?? 0);
    }

    public function has(int $flag): bool
    {
        return ($this->flags() & $flag) !== 0;
    }

    /**
     * The roles of the region: the names, in the config of Robust, of its flags that can be given there.
     *
     * @return list<string>
     */
    public function roles(): array
    {
        return self::flagNames($this->flags());
    }

    /** Whether visitors arrive here when they do not say where (the default region, or the one of the visitors of other grids). */
    public function isDefault(): bool
    {
        return $this->has(self::DEFAULT_REGION | self::DEFAULT_HGREGION);
    }

    public function isFallback(): bool
    {
        return $this->has(self::FALLBACK_REGION);
    }

    /** Whether the region is registered as running. */
    public function isOnline(): bool
    {
        return $this->has(self::REGION_ONLINE);
    }

    /** Whether it is a link to a region of another grid, not a region of this one. */
    public function isHyperlink(): bool
    {
        return $this->has(self::HYPERLINK);
    }

    public function lastSeen(): ?\DateTimeImmutable
    {
        return self::date($this->row['last_seen'] ?? 0);
    }

    /** Its place on the map, in regions of 256 meters. */
    public function x(): int
    {
        return intdiv((int) ($this->row['locX'] ?? 0), self::DEFAULT_SIZE);
    }

    public function y(): int
    {
        return intdiv((int) ($this->row['locY'] ?? 0), self::DEFAULT_SIZE);
    }

    /** The size in meters, 256 when the table does not say it. */
    public function width(): int
    {
        return (int) ($this->row['sizeX'] ?? 0) ?: self::DEFAULT_SIZE;
    }

    public function height(): int
    {
        return (int) ($this->row['sizeY'] ?? 0) ?: self::DEFAULT_SIZE;
    }

    public function detail(): array
    {
        $row = $this->row;

        return [
            'id' => $this->id(),
            'uuid' => $this->uuid(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'uri' => $this->uri(),
            'location' => $this->x() . ',' . $this->y(),
            'x' => $this->x(),
            'y' => $this->y(),
            'size' => $this->width() . 'x' . $this->height(),
            'width' => $this->width(),
            'height' => $this->height(),
            'ip' => $row['serverIP'] ?? null,
            'port' => isset($row['serverPort']) ? (int) $row['serverPort'] : null,
            'serverUri' => $row['serverURI'] ?? null,
            'handle' => $row['regionHandle'] ?? null,
            'owner' => $this->owner(),
            'ownerName' => $row['ownerName'] ?? null,
            'flags' => $this->flags(),
            'roles' => $this->roles(),
            'flagLabels' => array_values(self::flagLabels($this->flags())),
            'isDefault' => $this->isDefault(),
            'isFallback' => $this->isFallback(),
            'online' => $this->isOnline(),
            'hyperlink' => $this->isHyperlink(),
            'lastSeen' => ((int) ($row['last_seen'] ?? 0)) ?: null,
            'access' => isset($row['access']) ? (int) $row['access'] : null,
            'mapImage' => $row['regionMapTexture'] ?? null,
        ];
    }

    /**
     * The regions of the grid. A link to a region of another grid is not one of them unless asked.
     *
     * Options: `search` (words of the name), `sort` ("name", "lastSeen desc"...), `filters` (`online`, `default`, `fallback`,
     * `hyperlink`: yes or no; `hyperlink` is no unless given), `page` and `perPage`.
     *
     * @throws ChannelUnavailable when the database of the grid service cannot be reached
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        $pdo = self::connect($config, 'GridService', null, 'read the regions');
        [$sortName, $direction] = array_pad(preg_split('/\s+/', trim((string) ($options['sort'] ?? 'name'))) ?: [], 2, 'asc');
        if (!isset(self::SORTS[$sortName])) {
            [$sortName, $direction] = ['name', 'asc'];
        }
        $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';

        $where = [];
        $params = [];
        $words = preg_split('/\s+/', trim((string) ($options['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $i => $word) {
            $where[] = "r.regionName LIKE :w$i";
            $params["w$i"] = '%' . str_replace(['%', '\\'], '', $word) . '%';
        }
        $filters = [
            'online' => self::REGION_ONLINE,
            'default' => self::DEFAULT_REGION | self::DEFAULT_HGREGION,
            'fallback' => self::FALLBACK_REGION,
            'hyperlink' => self::HYPERLINK,
        ];
        $asked = (array) ($options['filters'] ?? []);
        $asked['hyperlink'] = ($asked['hyperlink'] ?? '') === 'yes' ? 'yes' : 'no';
        $applied = [];
        foreach ($filters as $name => $mask) {
            if (in_array($asked[$name] ?? null, ['yes', 'no'], true)) {
                $where[] = '(r.flags & ' . $mask . ($asked[$name] === 'yes' ? ') <> 0' : ') = 0');
                $applied[$name] = $asked[$name];
            }
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $total = (int) $pdo->get_var("SELECT COUNT(*) FROM regions r$clause", $params);
        $order = implode(', ', array_map(static fn(string $e): string => $e . ' ' . strtoupper($direction), self::SORTS[$sortName])) . ', r.uuid';
        $sql = 'SELECT ' . self::COLUMNS . " FROM regions r$clause ORDER BY $order";
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        if ($perPage > 0) {
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
        }
        $rows = self::withOwnerNames($config, $pdo->get_results($sql, $params, \PDO::FETCH_ASSOC));

        return new Collection(
            array_map(static fn(array $row): self => new self($config, $row), $rows),
            [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'sort' => "$sortName $direction",
                'search' => trim((string) ($options['search'] ?? '')),
                'columns' => self::columns(),
                'filters' => array_fill_keys(array_keys($filters), ['yes', 'no']),
                'applied' => $applied,
            ],
        );
    }

    /** A region by its UUID or its name. */
    public static function find(GridConfig $config, string $id): ?static
    {
        $pdo = Connections::pdo($config, 'GridService');
        if ($pdo === null) {
            return null;
        }
        $isUuid = preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $id) === 1;
        $rows = $pdo->get_results(
            'SELECT ' . self::COLUMNS . ' FROM regions r WHERE ' . ($isUuid ? 'r.uuid = :id' : 'r.regionName = :id') . ' ORDER BY r.flags & ' . self::HYPERLINK . ', r.uuid',
            ['id' => $isUuid ? strtolower($id) : $id],
            \PDO::FETCH_ASSOC,
        );
        $rows = self::withOwnerNames($config, $rows);

        return isset($rows[0]) ? new self($config, $rows[0]) : null;
    }

    /**
     * The columns of a list.
     *
     * @return list<array{name:string,label:string,sortable?:bool}>
     */
    public static function columns(): array
    {
        $columns = [
            ['name' => 'name', 'label' => _('Name')],
            ['name' => 'location', 'label' => _('Location')],
            ['name' => 'size', 'label' => _('Size')],
            ['name' => 'owner', 'label' => _('Owner')],
            ['name' => 'flags', 'label' => _('Flags')],
            ['name' => 'online', 'label' => _('Online')],
            ['name' => 'lastSeen', 'label' => _('Last seen')],
        ];

        return array_map(static fn(array $column): array => $column + ['sortable' => isset(self::SORTS[$column['name']])], $columns);
    }

    /** What a flag is called for a person ("Default Region"). */
    public static function label(int $flag): string
    {
        return self::flagLabels($flag)[$flag] ?? self::flagName($flag);
    }

    /** The name of a flag in the config of Robust (DefaultRegion for DEFAULT_REGION). */
    public static function flagName(int $flag): string
    {
        return self::NAMES[$flag] ?? throw new \InvalidArgumentException("$flag is not a flag of a region");
    }

    /** The flag a name of the config of Robust stands for, 0 for a name that is not one. */
    public static function flagValue(string $name): int
    {
        return (int) array_search($name, self::NAMES, true);
    }

    /**
     * The flags of a value, with a label to show.
     *
     * @return array<int,string> flag => label
     */
    public static function flagLabels(int $flags): array
    {
        $labels = [
            self::DEFAULT_REGION => _('Default Region'),
            self::DEFAULT_HGREGION => _('Default HG Region'),
            self::FALLBACK_REGION => _('Fallback Region'),
            self::REGION_ONLINE => _('Online'),
            self::AUTHENTICATE => _('Authenticate'),
            self::HYPERLINK => _('Hyperlink'),
            self::LOCKED_OUT => _('Locked Out'),
            self::NO_DIRECT_LOGIN => _('No Direct Login'),
            self::NO_MOVE => _('No Move'),
            self::PERSISTENT => _('Persistent'),
            self::RESERVATION => _('Reservation'),
        ];

        return array_filter($labels, static fn(int $flag): bool => ($flags & $flag) !== 0, ARRAY_FILTER_USE_KEY);
    }

    /**
     * The names, in the config of Robust, of the flags of a value that can be given there (DefaultRegion, DefaultHGRegion,
     * FallbackRegion, NoDirectLogin, Persistent, LockedOut, Reservation, NoMove, Authenticate).
     *
     * @return list<string>
     */
    public static function flagNames(int $flags): array
    {
        $names = [];
        foreach (self::NAMES as $flag => $name) {
            if (($flags & $flag) !== 0) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The flags the regions of a Robust config have: the values of its Region_<Name> keys, read as Robust reads them (quotes around the
     * value or not, a comment after it, the examples that are commented left out).
     *
     * @return list<string> their names
     */
    public static function rolesIn(string $text): array
    {
        $found = [];
        foreach (ConfigReader::parse($text) as $section) {
            foreach ($section as $key => $value) {
                if (!str_starts_with((string) $key, 'Region_')) {
                    continue;
                }
                foreach (explode(',', (string) $value) as $flag) {
                    $flag = trim($flag);
                    $flag === '' || $found[$flag] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Give flags to a region in the Robust config, the ones it has already kept. The line goes where the config shows an example of
     * it, and a flag given to another region is not asked twice.
     *
     * @param list<string> $roles names
     */
    public static function giveRoles(string $robustIni, string $region, array $roles): void
    {
        $ini = IniEditor::load($robustIni);
        $key = RegionName::configKey($region);
        $flags = array_filter(array_map('trim', explode(',', (string) $ini->get('GridService', $key))));
        $flags = array_values(array_unique([...$flags, ...$roles, self::flagName(self::PERSISTENT)]));
        $ini->set('GridService', $key, '"' . implode(', ', $flags) . '"', 'Region_');
        $ini->save($robustIni);
    }

    /**
     * The names of the owners of a page of regions, from the database of the user accounts.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function withOwnerNames(GridConfig $config, array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_column($rows, 'PrincipalID'), static fn($id): bool => $id !== null && $id !== '')));
        $accounts = $ids === [] ? null : Connections::pdo($config, 'UserAccountService');
        if ($accounts === null) {
            return $rows;
        }
        $names = [];
        $found = $accounts->get_results('SELECT PrincipalID, FirstName, LastName FROM UserAccounts WHERE PrincipalID IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids, \PDO::FETCH_ASSOC);
        foreach ($found as $row) {
            $names[$row['PrincipalID']] = trim($row['FirstName'] . ' ' . $row['LastName']);
        }
        foreach ($rows as &$row) {
            $row['ownerName'] = $names[$row['PrincipalID'] ?? ''] ?? null;
        }

        return $rows;
    }

    /**
     * The fields of a region, in the standard format.
     *
     * @param array{optional?:bool,roles?:array<string,string>,pool?:array<string,string>,fixed?:bool} $options
     *        optional: the first region of a simulator, which can be left out (asked first); roles: the roles no region of the grid
     *        has yet (role => name); pool: the simulators that can take the region (instance => name), with a new one by default;
     *        fixed: a region that exists, which only moves (its name, its roles and its simulator stay)
     * @return list<array<string,mixed>>
     */
    public static function fields(array $options = []): array
    {
        $optional = ($options['optional'] ?? false) === true;
        $roles = $options['roles'] ?? [];
        $pool = $options['pool'] ?? [];
        $fixed = ($options['fixed'] ?? false) === true;
        // The fields of a region that is made are only there when it is
        $made = $optional ? ['create' => true] : [];

        return [
            [
                'name' => 'create',
                'group' => 'region',
                'type' => 'boolean',
                'label' => _('Create the first region of this simulator?'),
                'enable' => $optional,
            ],
            [
                'name' => 'name',
                'group' => 'region',
                'type' => 'string',
                'required' => true,
                'label' => _('Region name'),
                'callback' => [self::class, 'validateName'],
                'condition' => $made,
                'enable' => !$fixed,
            ],
            [
                'name' => 'roles',
                'group' => 'region',
                'type' => 'checkbox',
                'multiple' => true,
                'label' => _('Role of this region'),
                'options' => $roles,
                'condition' => $made,
                'enable' => $roles !== [] && !$fixed,
            ],
            [
                'name' => 'location',
                'group' => 'region',
                'type' => 'string',
                'required' => true,
                'label' => _('Region location (x,y)'),
                'callback' => [Grid::class, 'validateCenter'],
                'condition' => $made,
            ],
            [
                'name' => 'sim',
                'group' => 'region',
                'type' => 'select',
                'label' => _('Simulator'),
                'options' => ['+' => _('Auto (add new)')] + $pool,
                'condition' => $made,
                'enable' => $pool !== [] && !$fixed,
            ],
            [
                'name' => 'port',
                'group' => 'region',
                'type' => 'integer',
                'required' => true,
                'follows' => ['sim'],
                'label' => _('Region port'),
                'callback' => [Grid::class, 'validatePort'],
                // The port of a region of a new simulator is in its block, known when the simulator is
                'condition' => $made + ($pool !== [] ? ['sim' => array_keys($pool)] : []),
            ],
        ];
    }

    /** The name of a region. An accent is not refused, it is transliterated (Noël becomes Noel). */
    public static function validateName(mixed $value): ?string
    {
        return RegionName::problem(Slug::ascii((string) $value));
    }
}
