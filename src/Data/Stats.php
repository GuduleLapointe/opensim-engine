<?php

declare(strict_types=1);

namespace OpenSim\Data;

use OpenSim\Config\GridConfig;

/**
 * The numbers of a grid: its members, who is in world, its regions and its area. Each is counted in the database of its own
 * service (the accounts in the one of UserAccountService, the last logins in GridUserService, who is in world in PresenceService,
 * the regions in GridService): Robust's unless the service has its own. Two services on one database are counted with one query, on
 * two databases a count asks the one for the identifiers and the other for who they are.
 */
final class Stats
{
    private const NULL_KEY = '00000000-0000-0000-0000-000000000000';

    /** How many identifiers are asked at once to the database of the accounts. */
    private const GROUP = 500;

    /**
     * The numbers of a grid.
     *
     * @return array<string,int|float>|null null when a database cannot be reached
     */
    public static function of(GridConfig $config, ?int $now = null): ?array
    {
        return self::counts(static fn(string $service): ?\OSPDO => Connections::pdo($config, $service), $now ?? time());
    }

    /**
     * The numbers, from the databases a function gives.
     *
     * @param callable(string):?\OSPDO $dbFor gives the database of a service of Robust
     * @return array{members:int,active_members:int,members_in_world:int,active_users:int,total_users:int,regions:int,area:int|float}|null
     *         null when a database cannot be reached
     */
    public static function counts(callable $dbFor, int $now): ?array
    {
        $accounts = $dbFor('UserAccountService');
        $users = $dbFor('GridUserService');
        $presence = $dbFor('PresenceService');
        $grid = $dbFor('GridService');
        foreach ([$accounts, $users, $presence, $grid] as $db) {
            if ($db === null || empty($db->connected)) {
                return null;
            }
        }
        // An integer in the statement: a bound value would be text, which a number is not compared with in every database
        $month = (int) ($now - 30 * 86400);
        $zero = self::NULL_KEY;
        $one = static function (\OSPDO $db, string $sql, array $params = []): int|float|string {
            $statement = $db->prepareAndExecute($sql, $params);

            return $statement ? $statement->fetchColumn() : 0;
        };
        // How many of the users are accounts: one query when both are in one database, else by groups of identifiers
        $accountsAmong = static function (\OSPDO $db, string $sql, array $params, string $idsSql) use ($accounts, $one): int {
            if ($db === $accounts) {
                return (int) $one($accounts, $sql, $params);
            }
            $statement = $db->prepareAndExecute($idsSql, $params);
            $ids = $statement ? $statement->fetchAll(\PDO::FETCH_COLUMN) : [];
            $count = 0;
            foreach (array_chunk($ids, self::GROUP) as $group) {
                $marks = implode(',', array_fill(0, count($group), '?'));
                $count += (int) $one($accounts, "SELECT COUNT(*) FROM UserAccounts WHERE PrincipalID IN ($marks)", $group);
            }

            return $count;
        };

        return [
            'members' => (int) $one($accounts, 'SELECT COUNT(*) FROM UserAccounts WHERE active = 1'),
            'active_members' => $accountsAmong(
                $users,
                "SELECT COUNT(*) FROM GridUser g, UserAccounts u WHERE u.PrincipalID = g.UserID AND g.Login + 0 > $month",
                [],
                "SELECT UserID FROM GridUser WHERE Login + 0 > $month",
            ),
            'members_in_world' => $accountsAmong(
                $presence,
                'SELECT COUNT(*) FROM Presence p, UserAccounts u WHERE p.RegionID != :zero AND p.UserID = u.PrincipalID',
                ['zero' => $zero],
                'SELECT UserID FROM Presence WHERE RegionID != :zero',
            ),
            'active_users' => (int) $one($users, "SELECT COUNT(*) FROM GridUser WHERE Login + 0 > $month"),
            'total_users' => (int) $one($presence, 'SELECT COUNT(*) FROM Presence WHERE RegionID != :zero', ['zero' => $zero]),
            'regions' => (int) $one($grid, 'SELECT COUNT(*) FROM regions'),
            'area' => ($area = $one($grid, 'SELECT ROUND(SUM(sizeX * sizeY) / 1000000.0, 2) FROM regions')) ? (float) $area : 0,
        ];
    }
}
