<?php

declare(strict_types=1);

/**
 * Someone to ban or to delete: found by a name or a UUID among the accounts and the visitors of other grids, and what they left in
 * the databases of the grid, each thing in the database of its service. The databases are in memory (SQLite).
 */

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\OSPDO;
use OpenSim\Models\Avatar;

const WHO_ADA = '11111111-1111-1111-1111-111111111111';
const WHO_ZED = '99999999-8888-7777-6666-000000000000';
const WHO_NOBODY = '55555555-5555-5555-5555-555555555555';

/** The files of a grid whose services keep their data where $split says (section => database), and one simulator. */
function who_grid(array $split = []): GridConfig
{
    $dir = test_tmp() . '/who-' . bin2hex(random_bytes(4));
    mkdir("$dir/sims", 0o755, true);
    $db = static fn(
        string $name,
    ) => "ConnectionString = \"Data Source=localhost;Database=$name;User ID=os;Password=pw;\"\n";
    $robust = "[DatabaseService]\n " . $db('robust');
    foreach ($split as $section => $name) {
        $robust .= "[$section]\n " . $db($name);
    }
    file_put_contents("$dir/Robust.HG.ini", $robust);
    file_put_contents("$dir/sims/alpha.ini", "[DatabaseService]\n " . $db('alpha_db'));

    return GridConfig::fromFolder($dir);
}

/** A database with all the tables someone leaves rows in, empty. */
function who_database(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec(
        'CREATE TABLE UserAccounts (PrincipalID CHAR(36) PRIMARY KEY, ScopeID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), Email VARCHAR(64), Created INT, UserLevel INT DEFAULT 0, UserFlags INT DEFAULT 0, UserTitle VARCHAR(64) DEFAULT \'\', active INT DEFAULT 1)',
    );
    $pdo->exec(
        'CREATE TABLE GridUser (UserID VARCHAR(255) PRIMARY KEY, HomeRegionID CHAR(36), LastRegionID CHAR(36), Online CHAR(5) DEFAULT \'false\', Login CHAR(16) DEFAULT \'0\', Logout CHAR(16) DEFAULT \'0\')',
    );
    $pdo->exec(
        'CREATE TABLE userprofile (useruuid CHAR(36) PRIMARY KEY, profileImage CHAR(36), profileAboutText TEXT, profileURL VARCHAR(255), profilePartner CHAR(36), profileWantToText TEXT, profileSkillsText TEXT, profileLanguages TEXT, profileFirstImage CHAR(36), profileFirstText TEXT, profileWantToMask INT DEFAULT 0, profileSkillsMask INT DEFAULT 0)',
    );
    $pdo->exec('CREATE TABLE tokens (UUID CHAR(36))');
    $pdo->exec('CREATE TABLE auth (UUID CHAR(36))');
    $pdo->exec('CREATE TABLE Avatars (PrincipalID CHAR(36))');
    $pdo->exec('CREATE TABLE Presence (UserID VARCHAR(255))');
    $pdo->exec('CREATE TABLE im_offline (PrincipalID CHAR(36))');
    $pdo->exec('CREATE TABLE inventoryfolders (folderID CHAR(36), agentID CHAR(36))');
    $pdo->exec('CREATE TABLE inventoryitems (inventoryID CHAR(36), avatarID CHAR(36))');
    $pdo->exec('CREATE TABLE assets (id CHAR(36), CreatorID VARCHAR(255))');
    $pdo->exec('CREATE TABLE prims (UUID CHAR(36), OwnerID CHAR(36))');
    $pdo->exec('CREATE TABLE hg_traveling_data (SessionID CHAR(36), UserID CHAR(36), GridExternalName VARCHAR(255))');

    return $pdo;
}

/** The databases by their name. */
function who_connections(array $databases): void
{
    Connections::using(
        static fn($what, $simulator, $connection): ?OSPDO => $databases[$connection?->database ?? ''] ?? null,
    );
}

afterEach(fn() => Connections::using(null));

describe('someone to find', function () {
    function who_world(): GridConfig
    {
        $robust = who_database();
        $robust->exec(
            "INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Email, Created) VALUES ('" .
                WHO_ADA .
                "', 'Ada', 'Love', 'ada@example.org', 1700000000)",
        );
        $robust->exec(
            "INSERT INTO GridUser (UserID) VALUES ('" .
                WHO_ADA .
                "'), ('" .
                WHO_ZED .
                ";http://other.example.org:8002/;Zed Visitor'), ('77777777-0000-0000-0000-000000000000;http://third.example.org:8002/;Ada Love')",
        );
        who_connections(['robust' => $robust, 'alpha_db' => who_database()]);

        return who_grid();
    }

    test('are an account of the grid, by its UUID', function (string $target) {
        $found = Avatar::lookup(who_world(), $target);

        expect($found)
            ->toHaveCount(1)
            ->and($found[0]->isLocal())
            ->toBeTrue()
            ->and($found[0]->label())
            ->toBe('Ada.Love')
            ->and($found[0]->uuid())
            ->toBe(WHO_ADA);
    })->with(['by UUID' => WHO_ADA, 'by UUID in capitals' => strtoupper(WHO_ADA)]);

    test('are a visitor of another grid, by its UUID or by its name, with the grid it comes from', function () {
        $config = who_world();

        $byUuid = Avatar::lookup($config, WHO_ZED);
        expect($byUuid)
            ->toHaveCount(1)
            ->and($byUuid[0]->isLocal())
            ->toBeFalse()
            ->and($byUuid[0]->grid())
            ->toBe('other.example.org:8002')
            ->and($byUuid[0]->label())
            ->toBe('Zed.Visitor@other.example.org:8002')
            ->and($byUuid[0]->uri())
            ->toBe('zed.visitor@other.example.org:8002');

        expect(array_map(static fn(Avatar $a): string => $a->label(), Avatar::lookup($config, 'Zed Visitor')))
            ->toBe(['Zed.Visitor@other.example.org:8002'])
            ->and(
                array_map(
                    static fn(Avatar $a): string => $a->label(),
                    Avatar::lookup($config, 'Zed.Visitor@other.example'),
                ),
            )
            ->toBe(['Zed.Visitor@other.example.org:8002']);
    });

    test('are all that have the name, the account and the visitors', function () {
        $labels = array_map(static fn(Avatar $a): string => $a->label(), Avatar::lookup(who_world(), 'Ada Love'));

        expect($labels)->toBe(['Ada.Love', 'Ada.Love@third.example.org:8002']);
    });

    test('are someone by their UUID alone when nobody here has it, and no one when no one has the name', function () {
        $config = who_world();
        $unknown = Avatar::lookup($config, WHO_NOBODY);

        expect($unknown)
            ->toHaveCount(1)
            ->and($unknown[0]->label())
            ->toBe(WHO_NOBODY)
            ->and($unknown[0]->isLocal())
            ->toBeTrue()
            ->and(Avatar::lookup($config, 'Nobody Here'))
            ->toBe([]);
    });

    test('are refused when the text is neither a name nor a UUID', function () {
        expect(fn() => Avatar::lookup(who_world(), "O'Neil Smith"))
            ->toThrow(InvalidArgumentException::class, 'neither a name')
            ->and(fn() => Avatar::lookup(who_world(), 'single'))
            ->toThrow(InvalidArgumentException::class);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('what someone left', function () {
    test('is deleted by statements for whoever reviews them, only where there is something', function () {
        $robust = who_database();
        $robust->exec(
            "INSERT INTO UserAccounts (PrincipalID, FirstName, LastName) VALUES ('" . WHO_ADA . "', 'Ada', 'Love')",
        );
        $robust->exec("INSERT INTO inventoryitems VALUES ('i1', '" . WHO_ADA . "'), ('i2', '" . WHO_ADA . "')");
        $robust->exec("INSERT INTO assets VALUES ('a1', '" . WHO_ADA . "')");
        $robust->exec("INSERT INTO hg_traveling_data VALUES ('s1', '" . WHO_ADA . "', 'other.example.org:8002')");
        $alpha = who_database();
        $alpha->exec("INSERT INTO prims VALUES ('p1', '" . WHO_ADA . "')");
        who_connections(['robust' => $robust, 'alpha_db' => $alpha]);
        $ada = Avatar::lookup(who_grid(), WHO_ADA)[0];

        expect($ada->purgeSql())
            ->toBe([
                '-- Delete 1 row from robust.UserAccounts',
                "DELETE FROM `robust`.`UserAccounts` WHERE PrincipalID = '" . WHO_ADA . "';",
                '-- Delete 2 rows from robust.inventoryitems',
                "DELETE FROM `robust`.`inventoryitems` WHERE avatarID = '" . WHO_ADA . "';",
                '-- Delete 1 row from robust.assets',
                "DELETE FROM `robust`.`assets` WHERE CreatorID = '" . WHO_ADA . "';",
                '-- Delete 1 row from alpha_db.prims',
                "DELETE FROM `alpha_db`.`prims` WHERE OwnerID = '" . WHO_ADA . "';",
            ])
            ->and($ada->travelingData())
            ->toBe([['SessionID' => 's1', 'UserID' => WHO_ADA, 'GridExternalName' => 'other.example.org:8002']]);
    });

    test('is deleted in the database of each service when the services have their own', function () {
        $databases = [
            'robust' => who_database(),
            'accounts' => who_database(),
            'assets' => who_database(),
            'profiles' => who_database(),
            'offline' => who_database(),
            'alpha_db' => who_database(),
        ];
        $databases['accounts']->exec(
            "INSERT INTO UserAccounts (PrincipalID, FirstName, LastName) VALUES ('" . WHO_ADA . "', 'Ada', 'Love')",
        );
        $databases['assets']->exec("INSERT INTO assets VALUES ('a1', '" . WHO_ADA . "')");
        $databases['profiles']->exec("INSERT INTO userprofile (useruuid) VALUES ('" . WHO_ADA . "')");
        $databases['offline']->exec("INSERT INTO im_offline VALUES ('" . WHO_ADA . "')");
        $databases['robust']->exec("INSERT INTO Presence VALUES ('" . WHO_ADA . "')");
        // What is in the wrong database is not theirs to find
        $databases['robust']->exec("INSERT INTO assets VALUES ('a2', '" . WHO_ADA . "')");
        who_connections($databases);
        $config = who_grid([
            'UserAccountService' => 'accounts',
            'AssetService' => 'assets',
            'UserProfilesService' => 'profiles',
            'Messaging' => 'offline',
        ]);

        $sql = implode("\n", Avatar::lookup($config, WHO_ADA)[0]->purgeSql());

        expect($sql)
            ->toContain('`accounts`.`UserAccounts`')
            ->toContain('`assets`.`assets`')
            ->toContain('`profiles`.`userprofile`')
            ->toContain('`offline`.`im_offline`')
            ->toContain('`robust`.`Presence`')
            ->not->toContain('`robust`.`assets`')
            ->not->toContain('`robust`.`UserAccounts`');
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');
