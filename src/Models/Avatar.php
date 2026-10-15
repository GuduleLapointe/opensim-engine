<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Provisioning\Slug;

/**
 * An avatar of the grid: its account (UserAccounts of the user account service), where it was (GridUser of the grid user service)
 * and its profile (userprofile of the profiles service). The three are read in one query when they are in the same database, which is
 * the usual case, else one query for each.
 */
final class Avatar extends Model
{
    /** The level of the accounts that are gods (administrators). */
    public const GOD_LEVEL = 200;

    /** What is read of an account, a visit and a profile. */
    private const ACCOUNT_COLUMNS = 'u.PrincipalID, u.FirstName, u.LastName, u.Email, u.Created, u.UserLevel, u.UserFlags, u.UserTitle, u.active';
    private const VISIT_COLUMNS = 'g.HomeRegionID, g.LastRegionID, g.Online, g.Login, g.Logout';
    private const PROFILE_COLUMNS = 'p.profileImage, p.profileAboutText, p.profileURL, p.profilePartner, p.profileWantToText, p.profileSkillsText, p.profileLanguages, p.profileFirstImage, p.profileFirstText';

    /** The account the services of the grid have, which is not a person: Robust makes it for itself. */
    private const TECHNICAL = ['GRID', 'SERVICES'];

    /** The moment of the last visit as the database computes it: the later of Login and Logout, stored as texts. */
    private const LAST_SEEN = 'CASE WHEN COALESCE(g.Login + 0, 0) > COALESCE(g.Logout + 0, 0) THEN COALESCE(g.Login + 0, 0) ELSE COALESCE(g.Logout + 0, 0) END';

    /** The columns a list can be sorted on, by their name in the collection: the expressions they are sorted by. */
    private const SORTS = [
        'name' => ['u.FirstName', 'u.LastName'],
        'email' => ['u.Email'],
        'created' => ['u.Created'],
        'level' => ['u.UserLevel'],
        'active' => ['u.active'],
        'lastSeen' => [self::LAST_SEEN],
        'online' => ["CASE WHEN g.Online = 'true' THEN 1 ELSE 0 END"],
    ];

    /** The sorts and filters that need the visits in the same query. */
    private const NEEDS_VISITS = ['lastSeen', 'online'];

    public function id(): string
    {
        return (string) $this->row['PrincipalID'];
    }

    public function uuid(): string
    {
        return $this->id();
    }

    public function name(): string
    {
        return trim($this->row['FirstName'] . ' ' . $this->row['LastName']);
    }

    public function slug(): string
    {
        return str_replace('_', '-', Slug::slug($this->name()));
    }

    /** The name of the avatar for the other grids: first.last@host:port. */
    public function uri(): ?string
    {
        $grid = $this->config->gridUri();

        return $grid === null ? null : strtolower($this->row['FirstName'] . '.' . $this->row['LastName']) . '@' . $grid;
    }

    /** The user flags of the account. */
    public function flags(): int
    {
        return (int) ($this->row['UserFlags'] ?? 0);
    }

    public function level(): int
    {
        return (int) ($this->row['UserLevel'] ?? 0);
    }

    public function isGod(): bool
    {
        return $this->level() >= self::GOD_LEVEL;
    }

    /** Whether the grid says the avatar is in the world now. */
    public function isOnline(): bool
    {
        return ($this->row['Online'] ?? 'false') === 'true';
    }

    public function isEnabled(): bool
    {
        return (int) ($this->row['active'] ?? 1) !== 0;
    }

    /** Whether it is an account of the services of the grid, not a person. */
    public function isTechnical(): bool
    {
        return [$this->row['FirstName'], $this->row['LastName']] === self::TECHNICAL;
    }

    /**
     * What it published in its profile as picks (the places it likes).
     *
     * @return list<array<string,mixed>> name, description, simname, posglobal, gatekeeper
     */
    public function picks(): array
    {
        $pdo = Connections::pdo($this->config, Database::Profiles);

        return $pdo === null ? [] : $pdo->get_results(
            "SELECT name, description, simname, posglobal, gatekeeper FROM userpicks WHERE creatoruuid = :id AND enabled = 'true' ORDER BY sortorder, name",
            ['id' => $this->id()],
            \PDO::FETCH_ASSOC,
        );
    }

    /**
     * Its classifieds that have not expired.
     *
     * @return list<array<string,mixed>> name, description, simname, parcelname, creationdate, expirationdate
     */
    public function classifieds(?int $now = null): array
    {
        $pdo = Connections::pdo($this->config, Database::Profiles);
        // An integer in the statement: a bound value would be text, which a number is not compared with in every database
        $now = (int) ($now ?? time());

        return $pdo === null ? [] : $pdo->get_results(
            "SELECT name, description, simname, parcelname, creationdate, expirationdate FROM classifieds WHERE creatoruuid = :id AND expirationdate + 0 > $now ORDER BY creationdate DESC",
            ['id' => $this->id()],
            \PDO::FETCH_ASSOC,
        );
    }

    public function lastSeen(): ?\DateTimeImmutable
    {
        return self::date($this->lastSeenTime());
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
            'firstName' => $row['FirstName'],
            'lastName' => $row['LastName'],
            'email' => $row['Email'] ?? null,
            'title' => $row['UserTitle'] ?? '',
            'created' => isset($row['Created']) ? (int) $row['Created'] : null,
            'level' => $this->level(),
            'isGod' => $this->isGod(),
            'flags' => $this->flags(),
            'active' => $this->isEnabled(),
            'online' => $this->isOnline(),
            'lastSeen' => $this->lastSeenTime() ?: null,
            'homeRegion' => $row['HomeRegionID'] ?? null,
            'lastRegion' => $row['LastRegionID'] ?? null,
            'technical' => $this->isTechnical(),
            'profileImage' => $row['profileImage'] ?? null,
            'about' => $row['profileAboutText'] ?? null,
            'profileUrl' => $row['profileURL'] ?? null,
            'partner' => self::uuidOrNull($row['profilePartner'] ?? null),
            'wants' => $row['profileWantToText'] ?? null,
            'skills' => $row['profileSkillsText'] ?? null,
            'languages' => $row['profileLanguages'] ?? null,
            'firstImage' => self::uuidOrNull($row['profileFirstImage'] ?? null),
            'firstLife' => $row['profileFirstText'] ?? null,
        ];
    }

    public function channels(): array
    {
        return Connections::pdo($this->config, 'UserAccountService') === null ? [] : ['database'];
    }

    /**
     * The values of an account that can be changed: email, title, level and active.
     *
     * @throws ChannelUnavailable
     * @throws \InvalidArgumentException for a value that is not one of these
     */
    public function update(array $values): void
    {
        $columns = ['email' => 'Email', 'title' => 'UserTitle', 'level' => 'UserLevel', 'active' => 'active'];
        $set = [];
        $params = ['id' => $this->id()];
        foreach ($values as $name => $value) {
            if (!isset($columns[$name])) {
                throw new \InvalidArgumentException("an avatar has no changeable '$name'");
            }
            $set[] = "{$columns[$name]} = :$name";
            $params[$name] = $name === 'active' ? (int) (bool) $value : $value;
        }
        if ($set === []) {
            return;
        }
        $pdo = self::connect($this->config, 'UserAccountService', null, 'change ' . $this->name());
        $pdo->prepareAndExecute('UPDATE UserAccounts SET ' . implode(', ', $set) . ' WHERE PrincipalID = :id', $params);
    }

    public function enable(): void
    {
        $this->update(['active' => true]);
    }

    public function disable(): void
    {
        $this->update(['active' => false]);
    }

    /**
     * The avatars of the grid.
     *
     * Options: `search` (words, each of them in the first name or the last name, and in the email with `searchEmail`: the emails are
     * not found by whoever asks, unless the caller says so), `sort` ("name", "lastSeen desc"...), `filters` (`active`, `online`, `god`,
     * `technical`: yes or no), `page` and `perPage`.
     *
     * @throws ChannelUnavailable when the database of the accounts cannot be reached
     */
    public static function all(GridConfig $config, array $options = []): Collection
    {
        $accounts = self::connect($config, 'UserAccountService', null, 'read the avatars');
        $joined = self::joined($config);
        [$sortName, $direction] = self::sort((string) ($options['sort'] ?? 'name'), $joined);
        [$where, $params, $applied] = self::conditions($options, $joined);

        $from = self::from($joined);
        $total = (int) $accounts->get_var("SELECT COUNT(*) FROM $from$where", $params);
        $sql = 'SELECT ' . self::select($joined) . " FROM $from$where ORDER BY " . self::order($sortName, $direction);
        $perPage = max(0, (int) ($options['perPage'] ?? 0));
        $page = max(1, (int) ($options['page'] ?? 1));
        if ($perPage > 0) {
            $sql .= ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
        }
        $rows = $accounts->get_results($sql, $params, \PDO::FETCH_ASSOC);
        if (!$joined) {
            $rows = self::withVisits($config, $rows);
        }

        return new Collection(
            array_map(static fn(array $row): self => new self($config, $row), $rows),
            [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'sort' => "$sortName $direction",
                'search' => trim((string) ($options['search'] ?? '')),
                'columns' => self::columns($joined),
                'filters' => self::filters($joined),
                'applied' => $applied,
            ],
        );
    }

    /**
     * An avatar by its UUID or its name (First Last, First.Last).
     */
    public static function find(GridConfig $config, string $id): ?static
    {
        $accounts = Connections::pdo($config, 'UserAccountService');
        if ($accounts === null) {
            return null;
        }
        if (preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $id)) {
            $where = ' WHERE u.PrincipalID = :a';
            $params = ['a' => strtolower($id)];
        } elseif (preg_match('/^([^\s.@]+)[\s.]([^\s.@]+)$/', trim($id), $m)) {
            $where = ' WHERE u.FirstName = :a AND u.LastName = :b';
            $params = ['a' => $m[1], 'b' => $m[2]];
        } else {
            return null;
        }
        $joined = self::joined($config);
        $rows = $accounts->get_results('SELECT ' . self::select($joined) . ' FROM ' . self::from($joined) . $where, $params, \PDO::FETCH_ASSOC);
        $rows = $joined ? $rows : self::withVisits($config, $rows);

        return isset($rows[0]) ? new self($config, $rows[0]) : null;
    }

    /**
     * The columns of a list.
     *
     * @return list<array{name:string,label:string,sortable?:bool}>
     */
    public static function columns(bool $joined = true): array
    {
        $columns = [
            ['name' => 'name', 'label' => _('Name')],
            ['name' => 'email', 'label' => _('Email')],
            ['name' => 'created', 'label' => _('Created')],
            ['name' => 'level', 'label' => _('Level')],
            ['name' => 'active', 'label' => _('Active')],
            ['name' => 'online', 'label' => _('Online')],
            ['name' => 'lastSeen', 'label' => _('Last seen')],
        ];

        return array_map(
            static fn(array $column): array => $column + [
                'sortable' => isset(self::SORTS[$column['name']]) && ($joined || !in_array($column['name'], self::NEEDS_VISITS, true)),
            ],
            $columns,
        );
    }

    /**
     * The filters of a list.
     *
     * @return array<string,list<string>>
     */
    private static function filters(bool $joined): array
    {
        $yesNo = ['yes', 'no'];

        return ['active' => $yesNo, 'god' => $yesNo, 'technical' => $yesNo] + ($joined ? ['online' => $yesNo] : []);
    }

    /** Whether the accounts, the visits and the profiles are in one database. */
    private static function joined(GridConfig $config): bool
    {
        $keys = [
            Connections::key($config, 'UserAccountService'),
            Connections::key($config, 'GridUserService'),
            Connections::key($config, Database::Profiles),
        ];

        return $keys[0] !== null && count(array_unique($keys)) === 1;
    }

    private static function from(bool $joined): string
    {
        return 'UserAccounts u' . ($joined
            ? ' LEFT JOIN GridUser g ON g.UserID = u.PrincipalID LEFT JOIN userprofile p ON p.useruuid = u.PrincipalID'
            : '');
    }

    private static function select(bool $joined): string
    {
        return self::ACCOUNT_COLUMNS . ($joined ? ', ' . self::VISIT_COLUMNS . ', ' . self::PROFILE_COLUMNS : '');
    }

    /** @return array{0:string,1:string} the sort and its direction; by name, ascending, when the sort asked is not available */
    private static function sort(string $sort, bool $joined): array
    {
        [$name, $direction] = array_pad(preg_split('/\s+/', trim($sort)) ?: [], 2, 'asc');
        if (!isset(self::SORTS[$name]) || (!$joined && in_array($name, self::NEEDS_VISITS, true))) {
            return ['name', 'asc'];
        }

        return [$name, strtolower((string) $direction) === 'desc' ? 'desc' : 'asc'];
    }

    private static function order(string $name, string $direction): string
    {
        $parts = array_map(static fn(string $expression): string => $expression . ' ' . strtoupper($direction), self::SORTS[$name]);

        return implode(', ', $parts) . ', u.PrincipalID';
    }

    /**
     * @param array<string,mixed> $options
     * @return array{0:string,1:array<string,string>,2:array<string,string>} the WHERE, its parameters, the filters applied
     */
    private static function conditions(array $options, bool $joined): array
    {
        $where = [];
        $params = [];
        $applied = [];
        $words = preg_split('/\s+/', trim((string) ($options['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $i => $word) {
            $where[] = "(u.FirstName LIKE :w$i ESCAPE '!' OR u.LastName LIKE :w$i ESCAPE '!'" . (($options['searchEmail'] ?? false) ? " OR u.Email LIKE :w$i ESCAPE '!'" : '') . ')';
            $params["w$i"] = self::contains($word);
        }
        $filters = [
            'active' => ['u.active = 1', 'u.active = 0'],
            'god' => ['u.UserLevel >= ' . self::GOD_LEVEL, 'u.UserLevel < ' . self::GOD_LEVEL],
            'technical' => [
                "(u.FirstName = '" . self::TECHNICAL[0] . "' AND u.LastName = '" . self::TECHNICAL[1] . "')",
                "NOT (u.FirstName = '" . self::TECHNICAL[0] . "' AND u.LastName = '" . self::TECHNICAL[1] . "')",
            ],
        ] + ($joined ? ['online' => ["g.Online = 'true'", "(g.Online IS NULL OR g.Online <> 'true')"]] : []);
        foreach ((array) ($options['filters'] ?? []) as $name => $value) {
            if (isset($filters[$name]) && in_array($value, ['yes', 'no'], true)) {
                $where[] = $filters[$name][$value === 'yes' ? 0 : 1];
                $applied[$name] = $value;
            }
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $params, $applied];
    }

    /**
     * The visits and the profiles of the accounts of a page, when they are in other databases.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function withVisits(GridConfig $config, array $rows): array
    {
        $ids = array_column($rows, 'PrincipalID');
        if ($ids === []) {
            return $rows;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $visits = [];
        $profiles = [];
        if ($pdo = Connections::pdo($config, 'GridUserService')) {
            foreach ($pdo->get_results("SELECT UserID, HomeRegionID, LastRegionID, Online, Login, Logout FROM GridUser WHERE UserID IN ($marks)", $ids, \PDO::FETCH_ASSOC) as $row) {
                $visits[$row['UserID']] = $row;
            }
        }
        if ($pdo = Connections::pdo($config, Database::Profiles)) {
            foreach ($pdo->get_results("SELECT useruuid, profileImage, profileAboutText, profileURL, profilePartner, profileWantToText, profileSkillsText, profileLanguages, profileFirstImage, profileFirstText FROM userprofile WHERE useruuid IN ($marks)", $ids, \PDO::FETCH_ASSOC) as $row) {
                $profiles[$row['useruuid']] = $row;
            }
        }
        foreach ($rows as &$row) {
            $row += array_diff_key($visits[$row['PrincipalID']] ?? [], ['UserID' => 1]) + array_diff_key($profiles[$row['PrincipalID']] ?? [], ['useruuid' => 1]);
        }

        return $rows;
    }

    /** A UUID, null for none or the null key. */
    private static function uuidOrNull(mixed $uuid): ?string
    {
        $uuid = (string) $uuid;

        return $uuid !== '' && $uuid !== '00000000-0000-0000-0000-000000000000' ? $uuid : null;
    }

    /** The time of the last visit: the later of the login and the logout, 0 when there is none. */
    private function lastSeenTime(): int
    {
        return max((int) ($this->row['Login'] ?? 0), (int) ($this->row['Logout'] ?? 0));
    }
}
