<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Text\Uuid;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Provisioning\Slug;

/**
 * A parcel: a part of a region with its own owner, name and rules, what the viewers call a place or land. It is a row of the `land`
 * table of the database of the simulator that runs its region, which also holds its popularity (`Dwell`).
 *
 * A parcel is made of the 4 x 4 meter squares its bitmap says: one bit a square, from the south-west corner of the region, row after
 * row, the lowest bit of a byte first (see holds()).
 *
 * The flags are the parcel flags of OpenSimulator, named as the constants of LSL (llGetParcelFlags) without their PARCEL_FLAG_
 * prefix; FOR_SALE and SHOW_DIRECTORY (shown in the search) are those of the protocol, which LSL has no constant for.
 */
final class Parcel extends Model
{
    /** The name of a parcel, in bytes: what the land panel of the viewers takes (the database holds 255). */
    private const NAME_BYTES = 63;

    /** The description of a parcel, in bytes: what the land panel of the viewers takes. */
    private const DESCRIPTION_BYTES = 255;

    public const ALLOW_FLY = 0x1;
    public const ALLOW_SCRIPTS = 0x2;
    public const FOR_SALE = 0x4;
    public const ALLOW_LANDMARK = 0x8;
    public const ALLOW_TERRAFORM = 0x10;
    public const ALLOW_DAMAGE = 0x20;
    public const ALLOW_CREATE_OBJECTS = 0x40;
    public const USE_ACCESS_GROUP = 0x100;
    public const USE_ACCESS_LIST = 0x200;
    public const USE_BAN_LIST = 0x400;
    public const USE_LAND_PASS_LIST = 0x800;
    public const SHOW_DIRECTORY = 0x1000;
    public const LOCAL_SOUND_ONLY = 0x8000;
    public const RESTRICT_PUSHOBJECT = 0x200000;
    public const ALLOW_GROUP_SCRIPTS = 0x2000000;
    public const ALLOW_CREATE_GROUP_OBJECTS = 0x4000000;
    public const ALLOW_ALL_OBJECT_ENTRY = 0x8000000;
    public const ALLOW_GROUP_OBJECT_ENTRY = 0x10000000;

    /** The side of a square of the bitmap, in meters. */
    public const SQUARE = 4;

    /** The length of the bitmap of a region of 256 meters: 64 x 64 squares, 8 to a byte. */
    private const LEGACY_BITMAP = 512;

    private const COLUMNS = 'UUID, RegionUUID, LocalLandID, Name, Description, OwnerUUID, IsGroupOwned, GroupUUID, Area, Category, ClaimDate, SalePrice, LandFlags, LandingType, SnapshotUUID, UserLocationX, UserLocationY, UserLocationZ, Dwell, MusicURL, MediaURL';

    /** The columns a list can be sorted on, by their name in the collection: the rows' keys. */
    private const SORTS = [
        'name' => 'Name',
        'area' => 'Area',
        'dwell' => 'Dwell',
        'claimed' => 'ClaimDate',
        'price' => 'SalePrice',
    ];

    public function id(): string
    {
        return (string) $this->row['UUID'];
    }

    public function uuid(): string
    {
        return $this->id();
    }

    public function name(): string
    {
        return (string) ($this->row['Name'] ?? '');
    }

    public function slug(): string
    {
        return Slug::slug($this->name());
    }

    /** Where it is reached from other grids: host:port:Region Name/x/y/z, its landing point. */
    public function uri(): ?string
    {
        $grid = $this->config->gridUri();
        $region = (string) ($this->row['regionName'] ?? '');
        if ($grid === null || $region === '') {
            return null;
        }
        $at = array_map(fn(string $key): int => (int) round((float) ($this->row[$key] ?? 0)), [
            'UserLocationX',
            'UserLocationY',
            'UserLocationZ',
        ]);

        return "$grid:$region/" . implode('/', $at);
    }

    /** The user who owns it, null when a group does. */
    public function owner(): ?string
    {
        return $this->isGroupOwned() ? null : $this->uuidOrNull($this->row['OwnerUUID'] ?? null);
    }

    /** The group that owns it. */
    public function group(): ?string
    {
        return $this->isGroupOwned()
            ? $this->uuidOrNull($this->row['GroupUUID'] ?? null) ?? $this->uuidOrNull($this->row['OwnerUUID'] ?? null)
            : null;
    }

    public function isGroupOwned(): bool
    {
        return (int) ($this->row['IsGroupOwned'] ?? 0) !== 0;
    }

    public function flags(): int
    {
        return (int) ($this->row['LandFlags'] ?? 0);
    }

    public function has(int $flag): bool
    {
        return ($this->flags() & $flag) !== 0;
    }

    /** Whether its owner shows it in the search. */
    public function isShownInSearch(): bool
    {
        return $this->has(self::SHOW_DIRECTORY);
    }

    public function isForSale(): bool
    {
        return $this->has(self::FOR_SALE);
    }

    /** The UUID of its region. */
    public function region(): string
    {
        return (string) ($this->row['RegionUUID'] ?? '');
    }

    /** Its area in square meters. */
    public function area(): int
    {
        return (int) ($this->row['Area'] ?? 0);
    }

    /** How popular it is: the visits the simulator counts. */
    public function dwell(): int
    {
        return (int) ($this->row['Dwell'] ?? 0);
    }

    /**
     * Whether it holds a point of its region.
     *
     * @param int|float $x meters from the west of the region
     * @param int|float $y meters from the south of the region
     * @param ?int $regionWidth the width of the region in meters; a bitmap that is not the one of a region of 256 meters needs it
     *                         unless the region is square
     * @throws \LogicException for a parcel that was listed, which has no bitmap: use find() or at()
     */
    public function holds(int|float $x, int|float $y, ?int $regionWidth = null): bool
    {
        $bitmap = $this->row['Bitmap'] ?? null;
        if (!is_string($bitmap)) {
            throw new \LogicException(
                'the bitmap of this parcel is not loaded: get it with Parcel::find() or Parcel::at()',
            );
        }
        $length = strlen($bitmap);
        $width = match (true) {
            $length === self::LEGACY_BITMAP => 64,
            $regionWidth !== null => intdiv($regionWidth, self::SQUARE),
            default => (int) round(sqrt($length * 8)),
        };
        $column = (int) floor($x / self::SQUARE);
        $row = (int) floor($y / self::SQUARE);
        if ($x < 0 || $y < 0 || $width < 1 || $column >= $width) {
            return false;
        }
        $square = $row * $width + $column;

        return $square >> 3 < $length && (ord($bitmap[$square >> 3]) & (1 << ($square & 7))) !== 0;
    }

    /** How many squares of 4 x 4 meters the bitmap holds, null when it is not loaded. */
    public function squares(): ?int
    {
        $bitmap = $this->row['Bitmap'] ?? null;

        return is_string($bitmap)
            ? array_sum(
                array_map(static fn(string $byte): int => substr_count(decbin(ord($byte)), '1'), str_split($bitmap)),
            )
            : null;
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
            'description' => $row['Description'] ?? '',
            'region' => $this->region(),
            'regionName' => $row['regionName'] ?? null,
            'localId' => isset($row['LocalLandID']) ? (int) $row['LocalLandID'] : null,
            'owner' => $this->owner(),
            'ownerName' => $row['ownerName'] ?? null,
            'group' => $this->group(),
            'isGroupOwned' => $this->isGroupOwned(),
            'area' => $this->area(),
            'category' => isset($row['Category']) ? (int) $row['Category'] : null,
            'claimed' => ((int) ($row['ClaimDate'] ?? 0)) ?: null,
            'price' => (int) ($row['SalePrice'] ?? 0),
            'forSale' => $this->isForSale(),
            'flags' => $this->flags(),
            'shownInSearch' => $this->isShownInSearch(),
            'dwell' => $this->dwell(),
            'landing' => [
                (float) ($row['UserLocationX'] ?? 0),
                (float) ($row['UserLocationY'] ?? 0),
                (float) ($row['UserLocationZ'] ?? 0),
            ],
            'snapshot' => $row['SnapshotUUID'] ?? null,
            'music' => $row['MusicURL'] ?? null,
            'media' => $row['MediaURL'] ?? null,
        ];
    }

    /**
     * The parcels of the grid, from the database of each of its simulators (a database shared by simulators is read once).
     *
     * Options: `search` (words of the name or the description), `sort` ("name", "dwell desc", "area", "claimed", "price"),
     * `filters` (`shown` in the search and `forSale`: yes or no; `region` and `owner`: a UUID), `page` and `perPage`.
     *
     * @throws ChannelUnavailable when no database of a simulator can be reached
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        [$sortName, $direction] = array_pad(
            preg_split('/\s+/', trim((string) ($options['sort'] ?? 'name'))) ?: [],
            2,
            'asc',
        );
        if (!isset(self::SORTS[$sortName])) {
            [$sortName, $direction] = ['name', 'asc'];
        }
        $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
        [$where, $params, $applied] = self::conditions($options);

        $rows = [];
        $reached = 0;
        foreach (self::databases($config) as $pdo) {
            $reached++;
            foreach (
                $pdo->get_results('SELECT ' . self::COLUMNS . " FROM land$where", $params, \PDO::FETCH_ASSOC)
                as $row
            ) {
                $rows[(string) $row['UUID']] = $row;
            }
        }
        if ($reached === 0) {
            throw ChannelUnavailable::for('read the parcels', 'no database of a simulator can be reached');
        }

        $key = self::SORTS[$sortName];
        $numeric = $sortName !== 'name';
        usort($rows, static function (array $a, array $b) use ($key, $numeric, $direction): int {
            $order = $numeric
                ? (float) ($a[$key] ?? 0) <=> (float) ($b[$key] ?? 0)
                : strcasecmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? ''));

            $order = $direction === 'desc' ? -$order : $order;

            return $order ?: strcmp((string) $a['UUID'], (string) $b['UUID']);
        });
        $total = count($rows);
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        if ($perPage > 0) {
            $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);
        }
        $rows = self::withNames($config, array_values($rows));

        return new Collection(array_map(static fn(array $row): self => new self($config, $row), $rows), [
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'sort' => "$sortName $direction",
            'search' => trim((string) ($options['search'] ?? '')),
            'columns' => self::columns(),
            'filters' => ['shown' => ['yes', 'no'], 'forSale' => ['yes', 'no'], 'region' => [], 'owner' => []],
            'applied' => $applied,
        ]);
    }

    /** A parcel by its UUID, with its bitmap. */
    public static function find(GridConfig $config, string $id): ?static
    {
        foreach (self::databases($config) as $pdo) {
            $rows = $pdo->get_results(
                'SELECT ' . self::COLUMNS . ', Bitmap FROM land WHERE UUID = :id',
                ['id' => strtolower($id)],
                \PDO::FETCH_ASSOC,
            );
            if ($rows !== []) {
                return new self($config, self::withNames($config, $rows)[0]);
            }
        }

        return null;
    }

    /**
     * The parcel that holds a point of a region.
     *
     * @param int|float $x meters from the west of the region
     * @param int|float $y meters from the south of the region
     */
    public static function at(GridConfig $config, string $region, int|float $x, int|float $y): ?static
    {
        $width = null;
        foreach (self::databases($config) as $pdo) {
            $rows = $pdo->get_results(
                'SELECT ' . self::COLUMNS . ', Bitmap FROM land WHERE RegionUUID = :region',
                ['region' => strtolower($region)],
                \PDO::FETCH_ASSOC,
            );
            if ($rows === []) {
                continue;
            }
            $width ??= Region::find($config, $region)?->width();
            foreach (self::withNames($config, $rows) as $row) {
                $parcel = new self($config, $row);
                if ($parcel->holds($x, $y, $width)) {
                    return $parcel;
                }
            }
        }

        return null;
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
            ['name' => 'regionName', 'label' => _('Region')],
            ['name' => 'ownerName', 'label' => _('Owner')],
            ['name' => 'area', 'label' => _('Area')],
            ['name' => 'dwell', 'label' => _('Traffic')],
            ['name' => 'forSale', 'label' => _('For sale')],
            ['name' => 'shownInSearch', 'label' => _('In search')],
        ];

        return array_map(
            static fn(array $column): array => $column + ['sortable' => isset(self::SORTS[$column['name']])],
            $columns,
        );
    }

    /** The name of a parcel, typed: any text, 63 bytes at most (an accent takes two). */
    public static function validateName(mixed $value): ?string
    {
        $name = trim((string) $value);

        return match (true) {
            $name === '' => _('required'),
            strlen($name) > self::NAME_BYTES => sprintf(_('too long (%d max)'), self::NAME_BYTES),
            default => null,
        };
    }

    /** The description of a parcel, typed: any text, 255 bytes at most. */
    public static function validateDescription(mixed $value): ?string
    {
        return strlen((string) $value) > self::DESCRIPTION_BYTES
            ? sprintf(_('too long (%d max)'), self::DESCRIPTION_BYTES)
            : null;
    }

    /** The flags of a value, with a label to show. @return array<int,string> */
    public static function flagLabels(int $flags): array
    {
        $labels = [
            self::ALLOW_FLY => _('Fly'),
            self::ALLOW_SCRIPTS => _('Scripts of others'),
            self::FOR_SALE => _('For sale'),
            self::ALLOW_LANDMARK => _('Landmarks'),
            self::ALLOW_TERRAFORM => _('Terraform'),
            self::ALLOW_DAMAGE => _('Damage'),
            self::ALLOW_CREATE_OBJECTS => _('Everyone builds'),
            self::USE_ACCESS_GROUP => _('Group access only'),
            self::USE_ACCESS_LIST => _('Access list'),
            self::USE_BAN_LIST => _('Ban list'),
            self::USE_LAND_PASS_LIST => _('Passes'),
            self::SHOW_DIRECTORY => _('In search'),
            self::LOCAL_SOUND_ONLY => _('Local sound'),
            self::RESTRICT_PUSHOBJECT => _('No push'),
        ];

        return array_filter($labels, static fn(int $flag): bool => ($flags & $flag) !== 0, ARRAY_FILTER_USE_KEY);
    }

    /**
     * The databases of the simulators, one for each distinct one.
     *
     * @return \Generator<int,OSPDO>
     */
    private static function databases(GridConfig $config): \Generator
    {
        $seen = [];
        foreach (array_keys($config->tree()['simulators'] ?? []) as $simulator) {
            $key = Connections::key($config, Database::Simulator, (string) $simulator);
            if ($key === null || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pdo = Connections::pdo($config, Database::Simulator, (string) $simulator);
            if ($pdo !== null) {
                yield $pdo;
            }
        }
    }

    /**
     * @param array<string,mixed> $options
     * @return array{0:string,1:array<string,string>,2:array<string,string>}
     */
    private static function conditions(array $options): array
    {
        $where = [];
        $params = [];
        $applied = [];
        $words = preg_split('/\s+/', trim((string) ($options['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $i => $word) {
            $where[] = "(Name LIKE :w$i ESCAPE '!' OR Description LIKE :w$i ESCAPE '!')";
            $params["w$i"] = self::contains($word);
        }
        $flags = ['shown' => self::SHOW_DIRECTORY, 'forSale' => self::FOR_SALE];
        foreach ((array) ($options['filters'] ?? []) as $name => $value) {
            if (isset($flags[$name]) && in_array($value, ['yes', 'no'], true)) {
                $where[] = '(LandFlags & ' . $flags[$name] . ($value === 'yes' ? ') <> 0' : ') = 0');
                $applied[$name] = $value;
            } elseif (
                in_array($name, ['region', 'owner'], true) &&
                Uuid::isValid((string) $value)
            ) {
                $where[] = ($name === 'region' ? 'RegionUUID' : 'OwnerUUID') . " = :$name";
                $params[$name] = strtolower((string) $value);
                $applied[$name] = strtolower((string) $value);
            }
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $applied];
    }

    /**
     * The name of the region and of the owner of each parcel, from the grid services.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function withNames(GridConfig $config, array $rows): array
    {
        $regions = [];
        $owners = [];
        $ids = static fn(string $column): array => array_values(
            array_unique(
                array_filter(array_column($rows, $column), static fn($id): bool => is_string($id) && $id !== ''),
            ),
        );
        $marks = static fn(array $list): string => implode(',', array_fill(0, count($list), '?'));
        if (($uuids = $ids('RegionUUID')) !== [] && ($pdo = Connections::pdo($config, 'GridService'))) {
            foreach (
                $pdo->get_results(
                    'SELECT uuid, regionName FROM regions WHERE uuid IN (' . $marks($uuids) . ')',
                    $uuids,
                    \PDO::FETCH_ASSOC,
                )
                as $row
            ) {
                $regions[strtolower((string) $row['uuid'])] = $row['regionName'];
            }
        }
        if (($uuids = $ids('OwnerUUID')) !== [] && ($pdo = Connections::pdo($config, 'UserAccountService'))) {
            foreach (
                $pdo->get_results(
                    'SELECT PrincipalID, FirstName, LastName FROM UserAccounts WHERE PrincipalID IN (' .
                        $marks($uuids) .
                        ')',
                    $uuids,
                    \PDO::FETCH_ASSOC,
                )
                as $row
            ) {
                $owners[strtolower((string) $row['PrincipalID'])] = trim($row['FirstName'] . ' ' . $row['LastName']);
            }
        }
        foreach ($rows as &$row) {
            $row['regionName'] = $regions[strtolower((string) ($row['RegionUUID'] ?? ''))] ?? null;
            $row['ownerName'] = $owners[strtolower((string) ($row['OwnerUUID'] ?? ''))] ?? null;
        }

        return $rows;
    }

    private function uuidOrNull(mixed $uuid): ?string
    {
        $uuid = (string) $uuid;

        return $uuid !== '' && $uuid !== '00000000-0000-0000-0000-000000000000' ? $uuid : null;
    }
}
