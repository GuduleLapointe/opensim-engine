<?php

declare(strict_types=1);

namespace OpenSim\Moderation;

use OpenSim\Config\ConnectionString;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Database;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\AccountWriter;
use OpenSim\Provisioning\DatabaseService;

/**
 * Bans users from a grid: finds them by name or UUID (the accounts of the grid and the visitors of other grids), and writes
 * the statements that delete their data (account, inventory, assets they created, objects) and add them to the ban list of the
 * estates. The databases are only read: the statements are for the administrator to review and run, the simulators stopped.
 */
final class UserBan
{
    /** What a user leaves in the database of the grid: table => condition, {id} being the UUID of the user. */
    private const TABLES = [
        'UserAccounts' => "PrincipalID = {id}",
        'GridUser' => "UserID = {id} OR UserID LIKE {like}",
        'userprofile' => 'useruuid = {id}',
        'tokens' => 'UUID = {id}',
        'auth' => 'UUID = {id}',
        'Avatars' => 'PrincipalID = {id}',
        'Presence' => 'UserID = {id}',
        'im_offline' => 'PrincipalID = {id}',
        'inventoryfolders' => 'agentID = {id}',
        'inventoryitems' => 'avatarID = {id}',
        'assets' => 'CreatorID = {id}',
    ];

    /**
     * @param \Closure(ConnectionString,string):?list<list<string>> $select the rows a query gives, null when it cannot be run
     */
    public function __construct(private readonly \Closure $select)
    {
    }

    /** The ban through the database service, with the accounts of the connection strings. */
    public static function with(DatabaseService $database): self
    {
        return new self(static function (ConnectionString $db, string $sql) use ($database): ?array {
            $lines = $database->query($db, $sql);

            return $lines === null ? null : array_map(static fn(string $line): array => explode("\t", $line), $lines);
        });
    }

    /**
     * The databases of a grid of this machine: its own, and those of its simulators (their estates and objects).
     *
     * @return array{0:?ConnectionString,1:list<ConnectionString>}
     */
    public static function databases(Grid $grid): array
    {
        $config = GridConfig::fromFolder($grid->directory());
        $robust = Database::Robust->connection($config);
        $others = [];
        foreach (array_keys($config->tree()['sims'] ?? []) as $simulator) {
            foreach ([Database::Simulator, Database::Estate] as $database) {
                $db = $database->connection($config, $simulator);
                if ($db !== null) {
                    $others[$db->key()] = $db;
                }
            }
        }

        return [$robust, array_values($others)];
    }

    /**
     * The statements that ban these users.
     *
     * @param list<string> $targets "First Last", "First.Last", "First.Last@grid" or a UUID
     * @param list<ConnectionString> $others the databases of the simulators
     */
    public function plan(array $targets, ConnectionString $robust, array $others = []): BanPlan
    {
        $plan = new BanPlan();
        $databases = [$robust->key() => $robust];
        foreach ($others as $db) {
            $databases[$db->key()] ??= $db;
        }

        foreach ($targets as $target) {
            $found = $this->find($robust, trim($target), $plan);
            foreach ($found as $user) {
                if (!in_array($user->uuid, array_map(static fn(BannedUser $u): string => $u->uuid, $plan->users), true)) {
                    $plan->users[] = $user;
                }
            }
        }

        foreach ($plan->users as $user) {
            $this->cleanup($plan, $user, $robust, $databases);
            $this->ban($plan, $user, $databases);
            $rows = $this->rows($robust, 'SELECT * FROM hg_traveling_data WHERE UserID = ' . AccountWriter::quote($user->uuid));
            foreach ($rows ?? [] as $row) {
                $plan->traveling[] = implode("\t", $row);
            }
        }

        return $plan;
    }

    /**
     * The users a name or a UUID stands for.
     *
     * @return list<BannedUser>
     */
    private function find(ConnectionString $robust, string $target, BanPlan $plan): array
    {
        $users = [];
        if (preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $target)) {
            $uuid = strtolower($target);
            $id = AccountWriter::quote($uuid);
            foreach ($this->rows($robust, "SELECT PrincipalID, FirstName, LastName FROM UserAccounts WHERE PrincipalID = $id") ?? [] as $row) {
                $users[] = new BannedUser($row[0], $row[1] ?? '', $row[2] ?? '');
            }
            foreach ($this->rows($robust, 'SELECT UserID FROM GridUser WHERE UserID LIKE ' . AccountWriter::quote("$uuid;%")) ?? [] as $row) {
                $users[] = self::visitor($row[0]);
            }

            // A UUID is enough to ban someone who never came here
            return $users !== [] ? $users : [new BannedUser($uuid)];
        }

        if (!preg_match('/^([A-Za-z0-9_-]+)[. ]([A-Za-z0-9_-]+)(?:@(\S+))?$/', $target, $m)) {
            $plan->problems[] = sprintf("'%s' is neither a name (First Last, First.Last@grid) nor a UUID", $target);

            return [];
        }
        [, $first, $last] = $m;
        $grid = $m[3] ?? '';
        if ($grid === '') {
            $sql = 'SELECT PrincipalID, FirstName, LastName FROM UserAccounts WHERE FirstName = ' . AccountWriter::quote($first) . ' AND LastName = ' . AccountWriter::quote($last);
            foreach ($this->rows($robust, $sql) ?? [] as $row) {
                $users[] = new BannedUser($row[0], $row[1] ?? '', $row[2] ?? '');
            }
        }
        // The visitors of other grids: GridUser keeps them as <uuid>;<home of the grid>;<First Last>
        $like = '%;' . ($grid !== '' ? '%' . self::like($grid) . '%;' : '') . self::like("$first $last");
        foreach ($this->rows($robust, 'SELECT UserID FROM GridUser WHERE UserID LIKE ' . AccountWriter::quote($like)) ?? [] as $row) {
            $users[] = self::visitor($row[0]);
        }
        if ($users === []) {
            $plan->problems[] = sprintf("no user found for '%s'", $target);
        }

        return $users;
    }

    /** A visitor from its GridUser id: <uuid>;<home of the grid>;<First Last> */
    private static function visitor(string $userId): BannedUser
    {
        $parts = explode(';', $userId);
        $name = preg_split('/\s+/', trim($parts[2] ?? ''), 2) ?: [];
        $grid = preg_replace('#^[a-z]+://|/+$#i', '', trim($parts[1] ?? '')) ?? '';

        return new BannedUser(strtolower($parts[0]), $name[0] ?? '', $name[1] ?? '', $grid);
    }

    /** Statements that delete what the user has in the databases. */
    private function cleanup(BanPlan $plan, BannedUser $user, ConnectionString $robust, array $databases): void
    {
        $id = AccountWriter::quote($user->uuid);
        $plan->cleanup[] = '-- ' . $user->label() . ' (' . $user->uuid . ')';
        foreach (self::TABLES as $table => $condition) {
            $condition = strtr($condition, ['{id}' => $id, '{like}' => AccountWriter::quote($user->uuid . ';%')]);
            $this->delete($plan, $robust, $table, $condition);
        }
        foreach ($databases as $db) {
            $this->delete($plan, $db, 'prims', "OwnerID = $id");
        }
    }

    /** The statement that deletes the rows of a table that match, when there are some. */
    private function delete(BanPlan $plan, ConnectionString $db, string $table, string $condition): void
    {
        $count = (int) ($this->rows($db, "SELECT count(*) FROM `$table` WHERE $condition")[0][0] ?? 0);
        if ($count > 0) {
            $plan->cleanup[] = sprintf('-- Delete %d row%s from %s.%s', $count, $count > 1 ? 's' : '', $db->database, $table);
            $plan->cleanup[] = sprintf('DELETE FROM %s.%s WHERE %s;', self::name($db->database), self::name($table), $condition);
        }
    }

    /** Statements that add the user to the ban list of every estate of the databases that have some. */
    private function ban(BanPlan $plan, BannedUser $user, array $databases): void
    {
        $id = AccountWriter::quote($user->uuid);
        foreach ($databases as $db) {
            if (($this->rows($db, "SHOW TABLES LIKE 'estateban'") ?? []) === []) {
                continue;
            }
            foreach ($this->rows($db, 'SELECT EstateID FROM estate_settings ORDER BY EstateID') ?? [] as [$estate]) {
                $estate = (int) $estate;
                if ((int) ($this->rows($db, "SELECT count(*) FROM estateban WHERE EstateID = $estate AND bannedUUID = $id")[0][0] ?? 0) > 0) {
                    continue;
                }
                $statement = sprintf(
                    "INSERT INTO %s.estateban (EstateID, bannedUUID, bannedIp, bannedIpHostMask) VALUES (%d, %s, '', '');",
                    self::name($db->database),
                    $estate,
                    $id,
                );
                if (!in_array($statement, $plan->ban, true)) {
                    $plan->ban[] = "-- {$user->label()} in estate $estate of {$db->database}";
                    $plan->ban[] = $statement;
                }
            }
        }
    }

    /** @return ?list<list<string>> */
    private function rows(ConnectionString $db, string $sql): ?array
    {
        return ($this->select)($db, $sql);
    }

    /** An identifier of a statement. */
    private static function name(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** A text for a LIKE, wildcards literal. */
    private static function like(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
    }
}
