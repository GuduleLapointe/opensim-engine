<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Text\Uuid;
use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridConfig;
use OpenSim\Config\GridFileService;
use OpenSim\Config\IniEditor;
use OpenSim\Data\Collection;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Data\OSPDO;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Provisioning\Slug;

/**
 * A region: a square of the map of a grid. It is one object wherever it is known from, with the same properties (name, slug,
 * place, size, address, validation of its name...); what differs is which of its methods can answer:
 *
 *  - the grid: its row in the regions table of the grid services (`all()`, `find()`): flags, owner, when it was last seen;
 *  - the config: the file of the simulator that runs it (`configured()`): its simulator, its port, whether the simulator loads it
 *    (it is enabled while the file ends in .ini). A region only the config knows was never started, or fails;
 *  - the search: its row in the tables of OpenSimSearch (`indexed()`).
 *
 * `merged()` has the regions of the grid with what the config says of them, then the ones only the config knows. A method that needs
 * a source the region does not come from answers what it can (nothing known: 0, null) or, for a change, is refused with
 * ChannelUnavailable. `in()` tells.
 *
 * The fields of a region are defined here, for whoever shows them: the setup of a grid, its administration, the page of the owner
 * of a region (see Forms\FieldSet).
 *
 * The flags are those of the regions table, named as in OpenSimulator (opensimulator.org/wiki/Regions_(database_table)). The ones a
 * region can be given are its roles; in the config of Robust they are written by name (see roles() and flagNames()).
 */
final class Region extends Model
{
    use HasName;

    /**
     * The rule of the name of a region, from the one of Second Life (Linden Lab, naming private regions): letters and digits, three
     * words at most, 3 characters at least (the map search needs 3), 50 at most (the column holds 128). No punctuation: the viewer
     * takes the dot for a host name, and Robust takes the underscore for a space (Ma Region and Ma_Region would be the same
     * Region_Ma_Region).
     */
    private const NAME = ['punctuation' => '', 'min' => 3, 'max' => 50, 'words' => 3];

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

    /** Where a region is known from. */
    public const GRID = 'grid';
    public const CONFIG = 'config';
    public const SEARCH = 'search';

    /** The suffix that disables the file of a region: the simulator loads the .ini ones only. */
    private const DISABLED = '.disabled';

    /** @var array<string,mixed> what the config says: simulator, file, enabled, name and the settings of the file */
    private array $local = [];

    /** @var array<string,mixed> the row of the search tables */
    private array $indexed = [];

    private const COLUMNS = 'r.uuid, r.regionName, r.regionHandle, r.locX, r.locY, r.sizeX, r.sizeY, r.serverIP, r.serverPort, r.serverURI, r.flags, r.last_seen, r.PrincipalID, r.access, r.ScopeID, r.regionMapTexture';

    /** The columns a list can be sorted on, by their name in the collection: the expressions they are sorted by. */
    private const SORTS = [
        'name' => ['r.regionName'],
        'location' => ['r.locX', 'r.locY'],
        'size' => ['r.sizeX', 'r.sizeY'],
        'lastSeen' => ['r.last_seen'],
        'flags' => ['r.flags'],
    ];

    /** Its UUID, else the simulator and the name for a region that has none yet. */
    public function id(): string
    {
        return $this->uuid() ?? ($this->local['simulator'] ?? '') . ':' . $this->name();
    }

    /** Its UUID, null while none was given (a region configured and never started has one in its file). */
    public function uuid(): ?string
    {
        $uuid = (string) ($this->row['uuid'] ?? ($this->local['RegionUUID'] ?? ($this->indexed['regionuuid'] ?? '')));

        return $uuid !== '' ? strtolower($uuid) : null;
    }

    public function name(): string
    {
        return (string) ($this->row['regionName'] ?? ($this->local['name'] ?? ($this->indexed['regionname'] ?? '')));
    }

    /** The sources that know it: grid, config, search. */
    public function sources(): array
    {
        return array_keys(
            array_filter([
                self::GRID => $this->row !== [],
                self::CONFIG => $this->local !== [],
                self::SEARCH => $this->indexed !== [],
            ]),
        );
    }

    /** Whether this source knows the region (see GRID, CONFIG, SEARCH). */
    public function in(string $source): bool
    {
        return in_array($source, $this->sources(), true);
    }

    /** The simulator whose file describes it, empty when the config does not know it. */
    public function simulator(): string
    {
        return (string) ($this->local['simulator'] ?? '');
    }

    /** The file that describes it in its simulator, empty when the config does not know it. */
    public function file(): string
    {
        return (string) ($this->local['file'] ?? '');
    }

    /** The address its simulator announces for it (ExternalHostName), null when its file does not say it. */
    public function externalHost(): ?string
    {
        $host = (string) ($this->local['ExternalHostName'] ?? '');

        return $host !== '' ? $host : null;
    }

    /** The port its simulator gives it, null when the config does not know it. */
    public function internalPort(): ?int
    {
        return isset($this->local['InternalPort']) ? (int) $this->local['InternalPort'] : null;
    }

    public function slug(): string
    {
        return Slug::slug($this->name());
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
        return isset($this->row['locX']) ? intdiv((int) $this->row['locX'], self::DEFAULT_SIZE) : $this->place()[0];
    }

    public function y(): int
    {
        return isset($this->row['locY']) ? intdiv((int) $this->row['locY'], self::DEFAULT_SIZE) : $this->place()[1];
    }

    /** @return array{0:int,1:int} the place its file gives, in regions */
    private function place(): array
    {
        return array_map('intval', explode(',', (string) ($this->local['Location'] ?? '0,0'))) + [0, 0];
    }

    /** The size in meters, 256 when nothing says it. */
    public function width(): int
    {
        return (int) ($this->row['sizeX'] ?? ($this->local['SizeX'] ?? 0)) ?: self::DEFAULT_SIZE;
    }

    public function height(): int
    {
        return (int) ($this->row['sizeY'] ?? ($this->local['SizeY'] ?? ($this->local['SizeX'] ?? 0))) ?:
            self::DEFAULT_SIZE;
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
            'sources' => $this->sources(),
            'simulator' => $this->simulator() ?: null,
            'file' => $this->file() ?: null,
            'internalPort' => $this->internalPort(),
            'enabled' => $this->in(self::CONFIG) ? $this->isEnabled() : null,
        ];
    }

    /** A region from its file in the regions folder of a simulator. */
    public static function fromConfig(
        GridConfig $config,
        string $simulator,
        string $file,
        string $name,
        array $values,
        bool $enabled,
    ): static {
        return (new static($config, []))->withConfig(
            ['name' => $name, 'simulator' => $simulator, 'file' => $file, 'enabled' => $enabled] + $values,
        );
    }

    /** A region from a row of the search tables. */
    public static function fromSearch(GridConfig $config, array $row): static
    {
        return (new static($config, []))->withSearch($row);
    }

    /**
     * The same region, with what its config says of it (see fromConfig()).
     *
     * @param array<string,mixed> $local
     */
    public function withConfig(array $local): static
    {
        $region = clone $this;
        $region->local = $local;

        return $region;
    }

    /** The same region, with its row of the search tables (its columns by their name in lower case). */
    public function withSearch(array $row): static
    {
        $region = clone $this;
        $region->indexed = array_change_key_case($row);

        return $region;
    }

    /**
     * The table of the search that holds the regions it knows: `regions`, unless the database is shared with Robust, whose `regions`
     * is another table (the search then keeps its own in `regionsregister`).
     */
    public static function searchTable(OSPDO $pdo): string
    {
        $robust = false;
        try {
            $robust = $pdo->query('SELECT uuid FROM regions LIMIT 1') !== false;
        } catch (\Throwable) {
        }

        return $pdo->table_exists('regions') && !$robust ? 'regions' : 'regionsregister';
    }

    /**
     * A region as the search engine knows it, null when the search has not got it or cannot be read. The search has its own
     * database (see Database::Search), which is not the grid's.
     */
    public static function inSearch(GridConfig $config, string $uuid): ?static
    {
        $pdo = Connections::pdo($config, Database::Search);
        if ($pdo === null) {
            return null;
        }
        $rows = $pdo->get_results(
            'SELECT * FROM ' . self::searchTable($pdo) . ' WHERE LOWER(regionUUID) = :id',
            ['id' => strtolower($uuid)],
            \PDO::FETCH_ASSOC,
        );

        return isset($rows[0]) ? static::fromSearch($config, $rows[0]) : null;
    }

    /**
     * The regions the files of a folder describe (a region folder of a simulator): one file each, named after the region, the section
     * of the file being its name. Those that end in .ini.disabled are the disabled ones.
     *
     * @return list<static> by name
     */
    public static function inDirectory(string $regionsDir, ?GridConfig $config = null, string $simulator = ''): array
    {
        $regions = [];
        $files = array_merge(glob("$regionsDir/*.ini") ?: [], glob("$regionsDir/*.ini" . self::DISABLED) ?: []);
        sort($files);
        foreach ($files as $file) {
            foreach (static::inFile($file, $config, $simulator) as $region) {
                $regions[$region->name()] = $region;
            }
        }
        ksort($regions);

        return array_values($regions);
    }

    /**
     * The regions one file describes (usually one).
     *
     * @return list<static>
     */
    public static function inFile(string $file, ?GridConfig $config = null, string $simulator = ''): array
    {
        $config ??= new GridConfig();
        $regions = [];
        foreach (ConfigReader::parse(GridFileService::read($file)) as $name => $values) {
            $name = trim((string) $name);
            $regions[] = static::fromConfig(
                $config,
                $simulator,
                $file,
                $name,
                $values,
                !str_ends_with($file, self::DISABLED),
            );
        }

        return $regions;
    }

    /**
     * The regions described by the files of the simulators of the grid that are on this machine, the ones its Robust does not know
     * included (never started, or failing).
     *
     * @param ?string $simulator only the ones of this simulator
     * @return list<static>
     */
    public static function configured(GridConfig $config, ?string $simulator = null): array
    {
        $regions = [];
        foreach ($config->tree()['simulators'] ?? [] as $name => $sections) {
            $dir = rtrim((string) ($sections['Startup']['regionload_regionsdir'] ?? ''), '/');
            if ($dir === '' || ($simulator !== null && $simulator !== '' && (string) $name !== $simulator)) {
                continue;
            }
            array_push($regions, ...static::inDirectory($dir, $config, (string) $name));
        }

        return $regions;
    }

    /**
     * A region by its key: its UUID, or the simulator and the name of one that has none yet. The grid's, with what the config says of
     * it; else the one only the config knows.
     */
    public static function locate(GridConfig $config, string $key): ?static
    {
        $configured = null;
        foreach (static::configured($config) as $region) {
            if (strcasecmp($region->id(), $key) === 0) {
                $configured = $region;
                break;
            }
        }
        $uuid = $configured?->uuid() ?? $key;
        $registered = null;
        if (Uuid::isValid($uuid)) {
            try {
                $registered = static::find($config, $uuid);
            } catch (ChannelUnavailable) {
                $registered = null;
            }
        }

        return $registered !== null && $configured !== null
            ? $registered->withConfig($configured->local)
            : $registered ?? $configured;
    }

    /**
     * The regions of the grid with what the config says of them, then the ones only the config knows. However many there are, a page
     * asks for its own. Options as all()'s, and `simulator` (only the regions its files describe). When the grid cannot be read,
     * only what is configured here is known and the collection says why (problem()).
     *
     * @param array{search?:string,sort?:string,filters?:array<string,string>,page?:int,perPage?:int,simulator?:string} $options
     */
    public static function merged(GridConfig $config, array $options = []): Collection
    {
        $simulator = (string) ($options['simulator'] ?? '');
        $local = static::configured($config, $simulator !== '' ? $simulator : null);
        $byUuid = [];
        foreach ($local as $region) {
            $region->uuid() === null || ($byUuid[$region->uuid()] = $region);
        }
        $perPage = max(1, (int) ($options['perPage'] ?? 25));
        $page = max(1, (int) ($options['page'] ?? 1));
        $filters = (array) ($options['filters'] ?? []);
        $search = (string) ($options['search'] ?? '');
        $request = [
            'search' => $search,
            'sort' => (string) ($options['sort'] ?? 'name'),
            'filters' => $filters,
            'page' => $page,
            'perPage' => $perPage,
        ];
        if ($simulator !== '') {
            $request['uuids'] = array_keys($byUuid);
        }

        $registered = [];
        $total = 0;
        $problem = null;
        $known = [];
        $meta = [];
        try {
            $list = static::all($config, $request);
            $registered = $list->items();
            $total = $list->total();
            $known = array_flip(static::known($config, array_keys($byUuid)));
            $meta = [
                'sort' => $list->sort(),
                'columns' => $list->columns(),
                'filters' => $list->filters(),
                'applied' => $list->applied(),
            ];
        } catch (ChannelUnavailable $e) {
            $problem = $e->getMessage();
        }

        $unknown = array_values(
            array_filter(
                $local,
                static fn(self $region): bool => ($region->uuid() === null || !isset($known[$region->uuid()])) &&
                    $region->matches($search, $filters),
            ),
        );
        usort($unknown, static fn(self $a, self $b): int => strcasecmp($a->name(), $b->name()));

        $items = array_map(
            static fn(self $region): self => isset($byUuid[$region->uuid()])
                ? $region->withConfig($byUuid[$region->uuid()]->local)
                : $region,
            $registered,
        );
        $take = $perPage - count($items);
        if ($take > 0) {
            array_push($items, ...array_slice($unknown, max(0, ($page - 1) * $perPage - $total), $take));
        }

        return new Collection(
            $items,
            [
                'total' => $total + count($unknown),
                'page' => $page,
                'perPage' => $perPage,
                'search' => trim($search),
                'problem' => $problem,
            ] + $meta,
        );
    }

    /**
     * Whether a region that only the config knows is in a search and the filters: its name has the words, and a filter on what only a
     * registered region has (its flags) keeps it when it asks for "no".
     *
     * @param array<string,mixed> $filters
     */
    private function matches(string $search, array $filters): bool
    {
        foreach (preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (stripos($this->name(), $word) === false) {
                return false;
            }
        }
        foreach ($filters as $filter) {
            if ($filter === 'yes') {
                return false;
            }
        }

        return true;
    }

    /** The estate it is in, null when none is known (no estate database can be reached, or none holds it). */
    public function estate(): ?Estate
    {
        $uuid = $this->uuid();
        if ($uuid === null) {
            return null;
        }
        try {
            return Estate::all($this->config, ['filters' => ['region' => $uuid]])->first();
        } catch (ChannelUnavailable) {
            return null;
        }
    }

    /** Whether the simulator loads it: its file ends in .ini. A region the config does not know is not one this machine disables. */
    public function isEnabled(): bool
    {
        return (bool) ($this->local['enabled'] ?? true);
    }

    /**
     * Have its simulator load it at its next start.
     *
     * @throws ChannelUnavailable when the config does not know it, or its file cannot be renamed
     */
    public function enable(): bool
    {
        $file = $this->file();
        if ($file === '') {
            throw ChannelUnavailable::for('enable ' . $this->name(), 'no file of a simulator describes it');
        }
        if (!str_ends_with($file, self::DISABLED)) {
            return true;
        }
        $enabled = substr($file, 0, -strlen(self::DISABLED));
        if (is_file($enabled) || !@rename($file, $enabled)) {
            throw ChannelUnavailable::for('enable ' . $this->name(), "$file cannot be renamed");
        }
        $this->local['file'] = $enabled;
        $this->local['enabled'] = true;

        return true;
    }

    /**
     * Stop its simulator from loading it at its next start. Its file keeps its description, and its UUID.
     *
     * @throws ChannelUnavailable when the config does not know it, or its file cannot be renamed
     */
    public function disable(): void
    {
        $file = $this->file();
        if ($file === '') {
            throw ChannelUnavailable::for('disable ' . $this->name(), 'no file of a simulator describes it');
        }
        if (str_ends_with($file, self::DISABLED)) {
            return;
        }
        if (!@rename($file, $file . self::DISABLED)) {
            throw ChannelUnavailable::for('disable ' . $this->name(), "$file cannot be renamed");
        }
        $this->local['file'] = $file . self::DISABLED;
        $this->local['enabled'] = false;
    }

    /**
     * The regions of the grid. A link to a region of another grid is not one of them unless asked.
     *
     * Options: `search` (words of the name), `sort` ("name", "lastSeen desc"...), `filters` (`online`, `default`, `fallback`,
     * `hyperlink`: yes or no; `hyperlink` is no unless given), `uuids` (only these regions), `page` and `perPage`.
     *
     * @throws ChannelUnavailable when the database of the grid service cannot be reached
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        $pdo = self::connect($config, 'GridService', null, 'read the regions');
        [$sortName, $direction] = array_pad(
            preg_split('/\s+/', trim((string) ($options['sort'] ?? 'name'))) ?: [],
            2,
            'asc',
        );
        if (!isset(self::SORTS[$sortName])) {
            [$sortName, $direction] = ['name', 'asc'];
        }
        $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';

        $where = [];
        $params = [];
        $words = preg_split('/\s+/', trim((string) ($options['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $i => $word) {
            $where[] = "r.regionName LIKE :w$i ESCAPE '!'";
            $params["w$i"] = self::contains($word);
        }
        if (isset($options['uuids'])) {
            $uuids = array_values(
                array_unique(array_map('strtolower', array_map('strval', (array) $options['uuids']))),
            );
            foreach ($uuids as $i => $uuid) {
                $params["u$i"] = $uuid;
            }
            $where[] =
                $uuids === []
                    ? '0 = 1'
                    : 'LOWER(r.uuid) IN (' .
                        implode(', ', array_map(static fn(int $i): string => ":u$i", array_keys($uuids))) .
                        ')';
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
        $order =
            implode(
                ', ',
                array_map(static fn(string $e): string => $e . ' ' . strtoupper($direction), self::SORTS[$sortName]),
            ) . ', r.uuid';
        $sql = 'SELECT ' . self::COLUMNS . " FROM regions r$clause ORDER BY $order";
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        if ($perPage > 0) {
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . ($page - 1) * $perPage;
        }
        $rows = self::withOwnerNames($config, $pdo->get_results($sql, $params, \PDO::FETCH_ASSOC));

        return new Collection(array_map(static fn(array $row): self => new self($config, $row), $rows), [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'sort' => "$sortName $direction",
            'search' => trim((string) ($options['search'] ?? '')),
            'columns' => self::columns(),
            'filters' => array_fill_keys(array_keys($filters), ['yes', 'no']),
            'applied' => $applied,
        ]);
    }

    /**
     * Which of these regions the grid knows (registered or linked).
     *
     * @param list<string> $uuids
     * @return list<string> lower case UUIDs
     * @throws ChannelUnavailable
     */
    public static function known(GridConfig $config, array $uuids): array
    {
        $pdo = self::connect($config, 'GridService', null, 'read the regions');
        $found = [];
        foreach (array_chunk(array_values(array_unique(array_map('strtolower', $uuids))), 500) as $group) {
            $marks = implode(',', array_fill(0, count($group), '?'));
            foreach (
                $pdo->get_results("SELECT uuid FROM regions WHERE LOWER(uuid) IN ($marks)", $group, \PDO::FETCH_COLUMN)
                as $uuid
            ) {
                $found[] = strtolower((string) $uuid);
            }
        }

        return $found;
    }

    /** A region by its UUID or its name. */
    public static function find(GridConfig $config, string $id): ?static
    {
        $pdo = Connections::pdo($config, 'GridService');
        if ($pdo === null) {
            return null;
        }
        $isUuid = Uuid::isValid($id);
        $rows = $pdo->get_results(
            'SELECT ' .
                self::COLUMNS .
                ' FROM regions r WHERE ' .
                ($isUuid ? 'r.uuid = :id' : 'r.regionName = :id') .
                ' ORDER BY r.flags & ' .
                self::HYPERLINK .
                ', r.uuid',
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

        return array_map(
            static fn(array $column): array => $column + ['sortable' => isset(self::SORTS[$column['name']])],
            $columns,
        );
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
        return \OpenSim\Support\Bitmask::names($flags, self::NAMES);
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
                    $flag === '' || ($found[$flag] = true);
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
        $key = self::configKey($region);
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
        $ids = array_values(
            array_unique(
                array_filter(array_column($rows, 'PrincipalID'), static fn($id): bool => $id !== null && $id !== ''),
            ),
        );
        $accounts = $ids === [] ? null : Connections::pdo($config, 'UserAccountService');
        if ($accounts === null) {
            return $rows;
        }
        $names = [];
        $found = $accounts->get_results(
            'SELECT PrincipalID, FirstName, LastName FROM UserAccounts WHERE PrincipalID IN (' .
                implode(',', array_fill(0, count($ids), '?')) .
                ')',
            $ids,
            \PDO::FETCH_ASSOC,
        );
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
                'normalize' => [self::class, 'normalizeName'],
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
                'name' => 'simulator',
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
                'follows' => ['simulator'],
                'label' => _('Region port'),
                'callback' => [Grid::class, 'validatePort'],
                // The port of a region of a new simulator is in its block, known when the simulator is
                'condition' => $made + ($pool !== [] ? ['simulator' => array_keys($pool)] : []),
            ],
        ];
    }

    /** The key of [GridService] giving the flags of a region: Robust looks for its name with the spaces replaced by underscores. */
    public static function configKey(string $name): string
    {
        return 'Region_' . str_replace(' ', '_', trim($name));
    }

    /** The name a key of [GridService] stands for (an underscore of the key may be a space of the name). */
    public static function fromConfigKey(string $key): string
    {
        return str_replace('_', ' ', (string) preg_replace('/^Region_/', '', $key));
    }

    /**
     * Whether a region of this name is among those known, the way Robust compares them (a space and an underscore are the same
     * character).
     *
     * @param array<string,mixed> $known the names as keys
     */
    public static function nameIsIn(string $name, array $known): bool
    {
        $same = static fn(string $value): string => strtolower(str_replace(' ', '_', trim($value)));
        $wanted = $same($name);
        foreach (array_keys($known) as $other) {
            if ($same((string) $other) === $wanted) {
                return true;
            }
        }

        return false;
    }
}
