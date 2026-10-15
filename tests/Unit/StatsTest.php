<?php

declare(strict_types=1);

/**
 * The numbers of a grid, counted in the database of each service. The databases are in memory (SQLite) with the tables of OpenSimulator.
 */

use OpenSim\Data\Stats;

const STATS_NOW = 1_800_000_000;

/** A database with the accounts (3 active, 1 not), the logins, the presences and the regions. */
function stats_database(array $tables = ['accounts', 'visits', 'presence', 'regions']): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    if (in_array('accounts', $tables, true)) {
        $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), active INT DEFAULT 1)');
        $pdo->exec("INSERT INTO UserAccounts VALUES ('u1', 1), ('u2', 1), ('u3', 1), ('u4', 0)");
    }
    if (in_array('visits', $tables, true)) {
        $recent = STATS_NOW - 86400;
        $old = STATS_NOW - 40 * 86400;
        $pdo->exec('CREATE TABLE GridUser (UserID VARCHAR(255), Login CHAR(16))');
        // u1 and u2 came this month, u3 long ago, and two visitors of other grids came this month
        $pdo->exec("INSERT INTO GridUser VALUES ('u1', '$recent'), ('u2', '$recent'), ('u3', '$old'), ('v1;http://other.example.org;Vis One', '$recent'), ('v2;http://other.example.org;Vis Two', '$recent')");
    }
    if (in_array('presence', $tables, true)) {
        $pdo->exec('CREATE TABLE Presence (UserID VARCHAR(255), RegionID CHAR(36))');
        // u1 is in world, a visitor too, u2 is logged out (a null region)
        $pdo->exec("INSERT INTO Presence VALUES ('u1', 'r1'), ('v1;http://other.example.org;Vis One', 'r1'), ('u2', '00000000-0000-0000-0000-000000000000')");
    }
    if (in_array('regions', $tables, true)) {
        $pdo->exec('CREATE TABLE regions (uuid VARCHAR(36), sizeX INT, sizeY INT)');
        $pdo->exec("INSERT INTO regions VALUES ('r1', 256, 256), ('r2', 512, 512), ('r3', 256, 256)");
    }

    return $pdo;
}

describe('The numbers of a grid', function () {
    test('are counted in one database when the services share it', function () {
        $db = stats_database();

        expect(Stats::counts(static fn() => $db, STATS_NOW))->toBe([
            'members' => 3,
            'active_members' => 2,
            'members_in_world' => 1,
            'active_users' => 4,
            'total_users' => 2,
            'regions' => 3,
            'area' => 0.39,
        ]);
    })->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

    test('are counted in the database of each service, asking the identifiers to one and who they are to the other', function () {
        $dbs = [
            'UserAccountService' => stats_database(['accounts']),
            'GridUserService' => stats_database(['visits']),
            'PresenceService' => stats_database(['presence']),
            'GridService' => stats_database(['regions']),
        ];

        expect(Stats::counts(static fn(string $service) => $dbs[$service], STATS_NOW))->toBe([
            'members' => 3,
            'active_members' => 2,
            'members_in_world' => 1,
            'active_users' => 4,
            'total_users' => 2,
            'regions' => 3,
            'area' => 0.39,
        ]);
    })->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

    test('are none when a database cannot be reached', function () {
        expect(Stats::counts(static fn() => null, STATS_NOW))->toBeNull();
    });
});
