<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Text\Uuid;

/**
 * An asset of the grid: what its users and its regions keep in the asset service, a texture, a sound, a notecard, a script, an
 * object. It is the same record whatever it holds (its type says what it is, `data()` is the bytes of the grid), and a type that
 * needs more of its own is a class that extends it (`Image` converts the pictures of the grid for the web); `Asset::TYPES` says
 * which. A record is read from the database of the assets, or from the asset service of the grid (`fromService()`), the one that
 * has it whatever it stores it in (the database, files).
 */
class Asset extends Model
{
    /**
     * The types OpenSimulator has, by number: the name, the content type of the data, and the class that handles it (this one when
     * it has none of its own).
     */
    public const TYPES = [
        0 => ['texture', 'image/x-j2c', Image::class],
        1 => ['sound', 'audio/ogg', self::class],
        2 => ['calling-card', 'application/vnd.ll.callingcard', self::class],
        3 => ['landmark', 'application/vnd.ll.landmark', self::class],
        5 => ['clothing', 'application/vnd.ll.clothing', self::class],
        6 => ['object', 'application/vnd.ll.primitive', self::class],
        7 => ['notecard', 'application/vnd.ll.notecard', self::class],
        8 => ['folder', 'application/vnd.ll.folder', self::class],
        10 => ['script', 'application/vnd.ll.lsltext', self::class],
        11 => ['script-bytecode', 'application/vnd.ll.lslbyte', self::class],
        12 => ['texture-tga', 'image/tga', Image::class],
        13 => ['body-part', 'application/vnd.ll.bodypart', self::class],
        17 => ['sound-wav', 'audio/x-wav', self::class],
        18 => ['image-tga', 'image/tga', Image::class],
        19 => ['image-jpeg', 'image/jpeg', Image::class],
        20 => ['animation', 'application/vnd.ll.animation', self::class],
        21 => ['gesture', 'application/vnd.ll.gesture', self::class],
        22 => ['simstate', 'application/x-metaverse-simstate', self::class],
        24 => ['link', 'application/vnd.ll.link', self::class],
        25 => ['link-folder', 'application/vnd.ll.linkfolder', self::class],
        49 => ['mesh', 'application/vnd.ll.mesh', self::class],
        56 => ['settings', 'application/vnd.ll.settings', self::class],
        57 => ['material', 'application/vnd.ll.material', self::class],
    ];

    /** What is read of an asset, the data left out (it can be megabytes). */
    private const COLUMNS = 'id, name, description, assetType, local, temporary, create_time, access_time, asset_flags, CreatorID, LENGTH(data) AS size';

    /** The columns a list can be sorted on, by name. */
    private const SORTS = [
        'name' => 'name',
        'type' => 'assetType',
        'created' => 'create_time',
        'accessed' => 'access_time',
        'size' => 'size',
    ];

    private ?string $data = null;

    public function id(): string
    {
        return strtolower((string) $this->row['id']);
    }

    public function uuid(): string
    {
        return $this->id();
    }

    public function name(): string
    {
        return (string) ($this->row['name'] ?? '');
    }

    public function slug(): string
    {
        return $this->id();
    }

    /** What the asset is: a texture, a notecard, a script... (the number is `typeNumber()`). */
    public function type(): string
    {
        return self::TYPES[$this->typeNumber()][0] ?? 'type-' . $this->typeNumber();
    }

    public function typeNumber(): int
    {
        return (int) ($this->row['assetType'] ?? ($this->row['Type'] ?? 0));
    }

    /** The content type of its data. */
    public function mime(): string
    {
        return self::TYPES[$this->typeNumber()][1] ?? 'application/octet-stream';
    }

    public function description(): string
    {
        return (string) ($this->row['description'] ?? '');
    }

    /** The UUID of who made it, as the grid keeps it (it may be a name and an address for a foreigner). */
    public function owner(): ?string
    {
        $creator = (string) ($this->row['CreatorID'] ?? '');

        return Uuid::isEmpty($creator) ? null : $creator;
    }

    public function flags(): int
    {
        return (int) ($this->row['asset_flags'] ?? 0);
    }

    /** Kept in the region that made it only. */
    public function isLocal(): bool
    {
        return filter_var($this->row['local'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** Kept for a while only. */
    public function isTemporary(): bool
    {
        return filter_var($this->row['temporary'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function created(): ?\DateTimeImmutable
    {
        return self::date($this->row['create_time'] ?? 0);
    }

    public function lastSeen(): ?\DateTimeImmutable
    {
        return self::date($this->row['access_time'] ?? 0);
    }

    /** The size of its data in bytes, null when it is not known. */
    public function size(): ?int
    {
        return isset($this->row['size'])
            ? (int) $this->row['size']
            : ($this->data !== null
                ? strlen($this->data)
                : null);
    }

    /**
     * The bytes of the asset as the grid keeps them (a texture is JPEG 2000). Read when asked, from the same place as the record.
     */
    public function data(): ?string
    {
        if ($this->data !== null) {
            return $this->data;
        }
        if (isset($this->row['data']) && is_string($this->row['data'])) {
            return $this->data = $this->row['data'];
        }
        $pdo = Connections::pdo($this->config, Database::Assets);
        $data = $pdo?->get_var('SELECT data FROM assets WHERE id = :id', ['id' => $this->id()]);

        return is_string($data) && $data !== '' ? ($this->data = $data) : null;
    }

    public function detail(): array
    {
        return [
            'id' => $this->id(),
            'uuid' => $this->uuid(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'description' => $this->description(),
            'type' => $this->type(),
            'typeNumber' => $this->typeNumber(),
            'mime' => $this->mime(),
            'local' => $this->isLocal(),
            'temporary' => $this->isTemporary(),
            'flags' => $this->flags(),
            'creator' => $this->owner(),
            'created' => isset($this->row['create_time']) ? ((int) $this->row['create_time'] ?: null) : null,
            'accessed' => isset($this->row['access_time']) ? ((int) $this->row['access_time'] ?: null) : null,
            'size' => $this->size(),
        ];
    }

    public function channels(): array
    {
        return Connections::pdo($this->config, Database::Assets) === null ? [] : ['database'];
    }

    /**
     * The assets of the grid, their data left out.
     *
     * Options: `search` (words, each of them in the name or the description), `sort` ("name", "created desc"...), `filters`
     * (`type`: the name of a type, `local`, `temporary`: yes or no), `page` and `perPage`.
     *
     * @throws ChannelUnavailable when the database of the assets cannot be reached
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        $pdo = self::connect($config, Database::Assets, null, 'read the assets');
        $where = [];
        $params = [];
        foreach (
            preg_split('/\s+/', trim((string) ($options['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []
            as $i => $word
        ) {
            $where[] = "(name LIKE :w$i ESCAPE '!' OR description LIKE :w$i ESCAPE '!')";
            $params["w$i"] = self::contains($word);
        }
        $applied = [];
        $filters = (array) ($options['filters'] ?? []);
        if (isset($filters['type']) && $filters['type'] !== '') {
            $number = array_search($filters['type'], array_column(self::TYPES, 0), true);
            $number =
                $number !== false
                    ? array_keys(self::TYPES)[$number]
                    : (ctype_digit((string) $filters['type'])
                        ? (int) $filters['type']
                        : null);
            if ($number !== null) {
                $where[] = 'assetType = :type';
                $params['type'] = $number;
                $applied['type'] = (string) $filters['type'];
            }
        }
        foreach (['local', 'temporary'] as $flag) {
            if (isset($filters[$flag]) && in_array($filters[$flag], ['yes', 'no'], true)) {
                $where[] = $filters[$flag] === 'yes' ? "$flag <> 0" : "$flag = 0";
                $applied[$flag] = $filters[$flag];
            }
        }
        $clause = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        [$sort, $direction] = array_pad(
            preg_split('/\s+/', trim((string) ($options['sort'] ?? 'name'))) ?: [],
            2,
            'asc',
        );
        isset(self::SORTS[$sort]) || ($sort = 'name');
        $direction = strtolower((string) $direction) === 'desc' ? 'DESC' : 'ASC';
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        $sql =
            'SELECT ' .
            self::COLUMNS .
            " FROM assets$clause ORDER BY " .
            self::SORTS[$sort] .
            " $direction, id" .
            ($perPage > 0 ? " LIMIT $perPage OFFSET " . ($page - 1) * $perPage : '');
        $rows = $pdo->get_results($sql, $params, \PDO::FETCH_ASSOC);

        return new Collection(array_map(static fn(array $row): self => self::make($config, $row), $rows), [
            'total' => (int) $pdo->get_var("SELECT COUNT(*) FROM assets$clause", $params),
            'page' => $page,
            'perPage' => $perPage,
            'sort' => strtolower("$sort $direction"),
            'search' => trim((string) ($options['search'] ?? '')),
            'columns' => [
                ['name' => 'name', 'label' => _('Name'), 'sortable' => true],
                ['name' => 'type', 'label' => _('Type'), 'sortable' => true],
                ['name' => 'size', 'label' => _('Size'), 'sortable' => true],
                ['name' => 'created', 'label' => _('Created'), 'sortable' => true],
            ],
            'filters' => [
                'type' => array_column(self::TYPES, 0),
                'local' => ['yes', 'no'],
                'temporary' => ['yes', 'no'],
            ],
            'applied' => $applied,
        ]);
    }

    public static function find(GridConfig $config, string $id): ?static
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $pdo = Connections::pdo($config, Database::Assets);
        $row = $pdo?->get_row(
            'SELECT ' . self::COLUMNS . ' FROM assets WHERE id = :id',
            ['id' => strtolower($id)],
            \PDO::FETCH_ASSOC,
        );

        return is_array($row) ? self::make($config, $row) : null;
    }

    /**
     * An asset from the asset service of the grid, at the address it gives (`<address>/assets/<id>`), whatever the service stores
     * it in. Null when the service does not have it or does not answer.
     *
     * @param \Closure(string):?string $http the body of an address, null when it does not answer
     */
    public static function fromService(GridConfig $config, string $address, string $id, \Closure $http): ?static
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $body = $http(rtrim($address, '/') . '/assets/' . strtolower($id));
        if (!is_string($body) || $body === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false || !isset($xml->Data)) {
            return null;
        }
        $data = base64_decode((string) $xml->Data, true);
        if ($data === false) {
            return null;
        }
        $asset = self::make($config, [
            'id' => (string) ($xml->ID ?? $id) ?: $id,
            'name' => (string) $xml->Name,
            'description' => (string) $xml->Description,
            'assetType' => (int) $xml->Type,
            'local' => (string) $xml->Local,
            'temporary' => (string) $xml->Temporary,
            'CreatorID' => (string) $xml->CreatorID,
            'size' => strlen($data),
        ]);
        $asset->data = $data;

        return $asset;
    }

    /** The asset of a record, of the class its type has. */
    protected static function make(GridConfig $config, array $row): static
    {
        $class = self::TYPES[(int) ($row['assetType'] ?? 0)][2] ?? self::class;

        return new $class($config, $row);
    }
}
