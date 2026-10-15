<?php

namespace OpenSim\Provisioning;

use OpenSim\Console\InstanceConsoleService;
use OpenSim\Models\GridInfo;
use OpenSim\Runtime\ShellService;

/**
 * The user accounts of a grid, kept by its Robust: read from its database,
 * created through its console (Robust makes the account, its inventory and its
 * password, which a row in the database would not).
 */
final class GridAccounts
{
    /** The home position of a new account: just above the sea level. */
    public const HOME_POSITION = '<128,128,22>';

    /** The home position Robust gives to a new account. */
    private const ROBUST_HOME_POSITION = '<128,128,0>';

    public function __construct(private DatabaseService $database, private UiInterface $ui) {}

    /** What a name of a person is made of: first and last name, as OpenSimulator has it. */
    public static function validName(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9._-]+ [A-Za-z0-9._-]+$/', $name) === 1;
    }

    /**
     * The accounts of people ("First Last"), oldest first, or null when the
     * database of the grid cannot be read.
     *
     * @return list<string>|null
     */
    public function names(GridInfo $grid): ?array
    {
        if ($grid->planned) {
            return []; // not made yet: no account
        }

        return $this->database->select(
            $grid->databasePlan(),
            "SELECT CONCAT(FirstName, ' ', LastName) FROM UserAccounts WHERE NOT (FirstName = 'GRID' AND LastName = 'SERVICES') ORDER BY Created",
        );
    }

    /** Whether an account exists, or null when it cannot be told. */
    public function exists(GridInfo $grid, string $name): ?bool
    {
        if (!self::validName($name)) {
            return false;
        }
        [$first, $last] = explode(' ', $name);
        $rows = $this->database->select(
            $grid->databasePlan(),
            "SELECT 1 FROM UserAccounts WHERE FirstName = '$first' AND LastName = '$last'",
        );

        return $rows === null ? null : $rows !== [];
    }

    /**
     * The statement that gives an account a home: the row of GridUser, with the names as Robust
     * creates the table. The position is HOME_POSITION, the look-at the one Robust gives to the home it sets.
     */
    public static function homeSql(string $userId, string $regionId): string
    {
        return "INSERT INTO GridUser (UserID, HomeRegionID, HomePosition, HomeLookAt) VALUES ('$userId', '$regionId', '" . self::HOME_POSITION . "', '<0,1,0>') " .
            'ON DUPLICATE KEY UPDATE HomeRegionID = VALUES(HomeRegionID), HomePosition = VALUES(HomePosition), HomeLookAt = VALUES(HomeLookAt)';
    }

    /**
     * Make a region the home of an account that has none: Robust sets the home of a new account
     * from its default region, which does not exist yet for the owner of the first estate (the
     * region needs its estate, the estate needs its owner). A home the user has is kept.
     *
     * @return bool|null whether the account has that home or one of its own, null when it cannot be told
     */
    public function setHome(GridInfo $grid, string $name, string $regionId): ?bool
    {
        if (!self::validName($name) || preg_match('/^[0-9a-fA-F-]{36}$/', $regionId) !== 1) {
            return false;
        }
        [$first, $last] = explode(' ', $name);
        $plan = $grid->databasePlan();
        $id = $this->database->select(
            $plan,
            "SELECT PrincipalID FROM UserAccounts WHERE FirstName = '$first' AND LastName = '$last'",
        );
        if ($id === null) {
            return null;
        }
        if ($id === []) {
            return false;
        }
        $home = $this->database->select($plan, "SELECT HomeRegionID FROM GridUser WHERE UserID = '{$id[0]}'");
        if ($home === null) {
            return null;
        }
        if ($home !== [] && trim($home[0]) !== '00000000-0000-0000-0000-000000000000') {
            return true;
        }

        return $this->database->select($plan, self::homeSql($id[0], $regionId)) !== null;
    }

    /**
     * Replace the home position Robust gave to an account by HOME_POSITION; any other position is kept.
     *
     * @return bool|null whether it could be written, null when it cannot be told
     */
    public function raiseHome(GridInfo $grid, string $name): ?bool
    {
        if (!self::validName($name)) {
            return false;
        }
        [$first, $last] = explode(' ', $name);
        $plan = $grid->databasePlan();
        $id = $this->database->select($plan, "SELECT PrincipalID FROM UserAccounts WHERE FirstName = '$first' AND LastName = '$last'");
        if ($id === null || $id === []) {
            return $id === null ? null : false;
        }

        return $this->database->select($plan, self::raiseHomeSql($id[0])) !== null;
    }

    /** The statement that moves a home from the position Robust gives to HOME_POSITION, and leaves any other home. */
    public static function raiseHomeSql(string $userId): string
    {
        return "UPDATE GridUser SET HomePosition = '" . self::HOME_POSITION . "' WHERE UserID = '$userId' AND HomePosition = '" . self::ROBUST_HOME_POSITION . "'";
    }

    /**
     * Create an account, through the console of the grid's Robust, which is
     * started when it is not running. Runs as the user of the instances.
     */
    public function create(GridInfo $grid, string $name, string $password, string $email): bool
    {
        $instance = $grid->robustInstance();
        // The answers of the prompts that follow: the email, a random ID, the default model
        // The console cuts a line at the spaces, except between double quotes
        $typed = preg_match('/\s/', $password) ? '"' . $password . '"' : $password;
        // Without an email the console asks for it too: three answers, an empty line each
        $lines = "create user $name $typed $email\n\n\n\n";

        if (!InstanceConsoleService::send($instance, $lines)) {
            $this->ui->note(sprintf(_("Starting the grid '%s' to create the account."), $grid->nick));
            [$code] = InstanceControl::run($this->ui, 'start', $grid->nick);
            if ($code !== 0 || !InstanceConsoleService::send($instance, $lines)) {
                $this->ui->error(
                    sprintf(_("Could not reach the console of the grid '%s' to create the account."), $grid->nick),
                );

                return false;
            }
        }

        // The console answers nothing to tell: look for the account. A Robust that has just started may not
        // take the command yet: it is sent again, a few times, before giving up (an account that exists
        // is refused by the console, which does no harm)
        for ($i = 0; $i < 24; $i++) {
            if ($this->exists($grid, $name) === true) {
                $this->raiseHome($grid, $name);

                return true;
            }
            sleep(1);
            if ($i % 8 === 7) {
                InstanceConsoleService::send($instance, $lines);
            }
        }
        $this->ui->error(sprintf(_('The account %s was not created: see the console of the grid.'), $name));

        return false;
    }
}
