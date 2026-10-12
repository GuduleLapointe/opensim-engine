<?php

declare(strict_types=1);

/**
 * Banning users: finding them by name or UUID, the statements that delete their data and ban them, nothing run.
 */

use OpenSim\Config\ConnectionString;
use OpenSim\Moderation\UserBan;

const BAN_UUID = '11111111-2222-3333-4444-555555555555';
const BAN_VISITOR = '99999999-8888-7777-6666-000000000000';

/**
 * A ban whose databases answer from a list: [pattern of the query => rows], the first that matches. What no pattern matches
 * cannot be run (null), as a table that is not there.
 *
 * @param array<string,list<list<string>>> $answers
 * @param list<string> $log the queries asked, filled
 */
function ban_with(array $answers, array &$log = []): UserBan
{
    return new UserBan(static function (ConnectionString $db, string $sql) use ($answers, &$log): ?array {
        $log[] = "{$db->database}: $sql";
        foreach ($answers as $pattern => $rows) {
            if (preg_match($pattern, $db->database . ': ' . $sql)) {
                return $rows;
            }
        }

        return null;
    });
}

function ban_db(string $name = 'grid'): ConnectionString
{
    return ConnectionString::for('localhost', $name, 'opensim', 'pw');
}

describe('Finding the users', function () {
    test('takes a UUID as it is when nobody has it, the account of the grid when it has one', function () {
        $plan = ban_with([])->plan([BAN_UUID], ban_db());
        expect($plan->users)->toHaveCount(1)->and($plan->users[0]->label())->toBe(BAN_UUID)->and($plan->problems)->toBe([]);

        $plan = ban_with(['/UserAccounts WHERE PrincipalID/' => [[BAN_UUID, 'Jane', 'Doe']]])->plan([strtoupper(BAN_UUID)], ban_db());
        expect($plan->users[0]->label())->toBe('Jane.Doe');
    });

    test('finds a name among the accounts and among the visitors of other grids, each user once', function () {
        $plan = ban_with([
            '/FROM UserAccounts WHERE FirstName/' => [[BAN_UUID, 'Jane', 'Doe']],
            '/FROM GridUser/' => [[BAN_VISITOR . ';http://other.example.org:8002/;Jane Doe'], [BAN_UUID]], // the second is the account again
        ])->plan(['Jane Doe', 'Jane.Doe'], ban_db());

        expect(array_map(static fn($u) => $u->label(), $plan->users))->toBe(['Jane.Doe', 'Jane.Doe@other.example.org:8002']);
    });

    test('looks for a visitor only when the grid is named, and keeps wildcards literal', function () {
        $log = [];
        ban_with([], $log)->plan(['Jane_Doe.Smith@other_grid'], ban_db());

        expect(implode("\n", $log))->not->toContain('FROM UserAccounts')
            ->and(implode("\n", $log))->toContain("LIKE '%;%other\\\\_grid%;Jane\\\\_Doe Smith'");
    });

    test('says what is not found and what is not a name', function () {
        $plan = ban_with([])->plan(['Nobody Here', "O'Neil Smith", 'single'], ban_db());

        expect($plan->users)->toBe([])
            ->and($plan->problems)->toBe(["no user found for 'Nobody Here'", "'O'Neil Smith' is neither a name (First Last, First.Last@grid) nor a UUID", "'single' is neither a name (First Last, First.Last@grid) nor a UUID"]);
    });
});

describe('The statements', function () {
    function ban_answers(): array
    {
        return [
            '/UserAccounts WHERE PrincipalID/' => [[BAN_UUID, 'Jane', 'Doe']],
            '/count\(\*\) FROM `(UserAccounts|inventoryitems)`/' => [['1']],
            '/count\(\*\) FROM `assets`/' => [['42']],
            '/^sim1: .*count\(\*\) FROM `prims`/' => [['7']],
            '/hg_traveling_data/' => [['row1', 'a']],
            "/^(grid|sim1): SHOW TABLES LIKE 'estateban'/" => [['estateban']],
            '/SELECT EstateID/' => [['1'], ['2']],
            '/^sim1: .*estateban WHERE EstateID = 2/' => [['1']],
            '/count\(\*\) FROM estateban/' => [['0']],
        ];
    }

    test('delete what the user owns, only where there is something', function () {
        $plan = ban_with(ban_answers())->plan([BAN_UUID], ban_db(), [ConnectionString::for('localhost', 'sim1', 'o', 'p')]);

        expect($plan->cleanup)->toBe([
            '-- Jane.Doe (' . BAN_UUID . ')',
            '-- Delete 1 row from grid.UserAccounts',
            "DELETE FROM `grid`.`UserAccounts` WHERE PrincipalID = '" . BAN_UUID . "';",
            '-- Delete 1 row from grid.inventoryitems',
            "DELETE FROM `grid`.`inventoryitems` WHERE avatarID = '" . BAN_UUID . "';",
            '-- Delete 42 rows from grid.assets',
            "DELETE FROM `grid`.`assets` WHERE CreatorID = '" . BAN_UUID . "';",
            '-- Delete 7 rows from sim1.prims',
            "DELETE FROM `sim1`.`prims` WHERE OwnerID = '" . BAN_UUID . "';",
        ])->and($plan->deletes())->toBe(4)->and($plan->traveling)->toBe(["row1\ta"]);
    });

    test('ban the user in every estate that does not ban them already, once', function () {
        $plan = ban_with(ban_answers())->plan([BAN_UUID, 'Jane Doe'], ban_db(), [ConnectionString::for('localhost', 'sim1', 'o', 'p'), ConnectionString::for('localhost', 'grid', 'opensim', 'pw')]);

        // sim1 has no estateban rows (its table answers like the grid's), the second estate of sim1 bans them already
        expect($plan->bans())->toBe(3)
            ->and($plan->ban[1])->toBe("INSERT INTO `grid`.estateban (EstateID, bannedUUID, bannedIp, bannedIpHostMask) VALUES (1, '" . BAN_UUID . "', '', '');")
            ->and(implode("\n", $plan->ban))->not->toContain('sim1.estateban (EstateID, bannedUUID, bannedIp, bannedIpHostMask) VALUES (2');
    });

    test('are in files for the administrator, nothing is run', function () {
        $log = [];
        $plan = ban_with(ban_answers(), $log)->plan([BAN_UUID], ban_db());
        $files = $plan->files('2026-10-12');

        expect(array_keys($files))->toBe(['users', 'cleanup', 'ban', 'hg_traveling_data'])
            ->and($files['users'])->toBe(BAN_UUID . "\tJane\tDoe\t\n")
            ->and($files['cleanup'])->toStartWith("-- Delete accounts and data 2026-10-12\n")
            ->and($files['ban'])->toContain('INSERT INTO')
            ->and(array_filter($log, static fn(string $q): bool => (bool) preg_match('/^\S+: (DELETE|INSERT|UPDATE)/i', $q)))->toBe([]);
    });
});
