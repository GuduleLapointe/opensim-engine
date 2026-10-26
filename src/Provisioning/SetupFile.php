<?php

namespace OpenSim\Provisioning;

use OpenSim\Result;
use OpenSim\Models\Avatar;
use Symfony\Component\Yaml\Yaml;

/**
 * A setup in a file (JSON or YAML): the grid, its simulators and regions, the owner, and the accounts
 * to make, in the format of the bulk import (`opensim import FILE --users` reads the `users` of this file).
 *
 *   grid:        name, nick, hostname, web_url, hypergrid, helpers, helpers_path, public_port, private_port,
 *                console (rest|screen), console_port, enable, start,
 *                options of a new grid: search (internal|shared|disabled), options (the presets that are on, by name: a list, or a
 *                map name => true|false: see GridOptions), or the answer each preset takes (economy: gloebit, ossl: true...),
 *                web_server (caddy|apache|nginx), web_site (helpers|website),
 *                database: driver (mysql|sqlite for standalone), host, name, user, password, admin_user, admin_password
 *   owner:       name (First Last), password, or password_hash and password_salt (as Robust keeps them),
 *                email (optional): the account that owns the estate
 *   simulators:  a list of: name, http_port, console, estate: name, owner, database: driver (mysql|sqlite), host, name, user, password,
 *                regions: a list of: name, location (x,y), port, roles (DefaultRegion, DefaultHGRegion, FallbackRegion,
 *                or default, default_hg, fallback); an adopted region also retains its uuid and original INI text in source
 *   users:       a list of: first, last, email (optional), password (made when empty) or password_hash
 *                and password_salt (written as they are)
 *
 * Only the grid name, and the database user and password, are required to make a grid: the rest has the default
 * of the setup. Without a grid name, the simulators join the grid of the `nick` (or the only one there is), and
 * need the user and password of their database. The file gives the answers about the grid, its simulators and their regions by the
 * names of the fields of the models (gridValues(), simulatorValues(), regionValues()): the planners work the plans out from them, as they
 * do from a form, and what the application asks once they are made is answered from the file too (gridPrompts()...).
 *
 * The file is also written by the setup, from what it did (the plans), in `setup.json` of the grid.
 */
final class SetupFile
{
    /** The fields of a grid (Models\Grid::fields()), and where their value is in the file */
    private const GRID = [
        'name' => 'grid.name',
        'nick' => 'grid.nick',
        'hostname' => 'grid.hostname',
        'web_url' => 'grid.web_url',
        'hypergrid' => 'grid.hypergrid',
        'standalone' => 'grid.standalone',
        'helpers' => 'grid.helpers',
        'helpers_path' => 'grid.helpers_path',
        'search' => 'grid.search',
        'offline' => 'grid.offline',
        'economy' => 'grid.economy',
        'ossl' => 'grid.ossl',
        'options' => 'grid.options',
        'web_server' => 'grid.web_server',
        'web_site' => 'grid.web_site',
        'public_port' => 'grid.public_port',
        'private_port' => 'grid.private_port',
        'center' => 'grid.center',
        'console' => 'grid.console',
        'console_port' => 'grid.console_port',
        'database_host' => 'grid.database.host',
        'database_driver' => 'grid.database.driver',
        'database_name' => 'grid.database.name',
        'database_user' => 'grid.database.user',
        'database_password' => 'grid.database.password',
    ];

    /** The same for a simulator (Models\Simulator::fields(); $i: its place in `simulators`) */
    private const SIM = [
        'name' => 'simulators.$i.name',
        'http_port' => 'simulators.$i.http_port',
        'console' => 'simulators.$i.console',
        'console_port' => 'simulators.$i.console_port',
        'database_driver' => 'simulators.$i.database.driver',
        'database_host' => 'simulators.$i.database.host',
        'database_name' => 'simulators.$i.database.name',
        'database_user' => 'simulators.$i.database.user',
        'database_password' => 'simulators.$i.database.password',
        'estate_name' => 'simulators.$i.estate.name',
    ];

    /** The same for a region (Models\Region::fields(); $i: its simulator, $j: its place in `regions`) */
    private const REGION = [
        'name' => 'simulators.$i.regions.$j.name',
        'location' => 'simulators.$i.regions.$j.location',
        'port' => 'simulators.$i.regions.$j.port',
        'roles' => 'simulators.$i.regions.$j.roles',
    ];

    /**
     * Read a setup, JSON or YAML (told from its first character, or by $format).
     *
     * @return array<string,mixed>
     * @throws \InvalidArgumentException  when it cannot be read or something required is missing or wrong
     */
    public static function parse(string $text, ?string $format = null): array
    {
        $text = ltrim($text, "\xEF\xBB\xBF");
        $format ??= in_array(substr(ltrim($text), 0, 1), ['{', '['], true) ? 'json' : 'yaml';
        try {
            $data = $format === 'json' ? json_decode($text, true, 512, JSON_THROW_ON_ERROR) : Yaml::parse($text);
        } catch (\JsonException | \Symfony\Component\Yaml\Exception\ParseException $e) {
            throw new \InvalidArgumentException('The setup file cannot be read: ' . $e->getMessage());
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException(
                'The setup file is a list of settings (grid, owner, simulators, users).',
            );
        }
        $problems = self::problems($data);
        if ($problems !== []) {
            throw new \InvalidArgumentException(implode("\n", $problems));
        }

        return $data;
    }

    public static function load(string $path): array
    {
        $text = is_readable($path) ? file_get_contents($path) : false;
        if ($text === false) {
            throw new \InvalidArgumentException("Cannot read $path");
        }

        return self::parse(
            $text,
            preg_match('/\.json$/i', $path) ? 'json' : (preg_match('/\.ya?ml$/i', $path) ? 'yaml' : null),
        );
    }

    /**
     * What is missing or wrong in a setup.
     *
     * @param array<string,mixed> $data
     * @return list<string>
     */
    public static function problems(array $data): array
    {
        $problems = [];
        $grid = $data['grid'] ?? [];
        $creates = is_array($grid) && trim((string) ($grid['name'] ?? '')) !== '';
        $standalone = Result::isTrue(self::get($data, 'grid.standalone'));
        $databaseDriver = strtolower(trim((string) self::get($data, 'grid.database.driver')));
        $databaseDriver = $databaseDriver !== '' ? $databaseDriver : 'mysql';
        if (!in_array($databaseDriver, ['mysql', 'sqlite'], true)) {
            $problems[] = 'grid.database.driver must be mysql or sqlite';
        } elseif (!$standalone && $databaseDriver !== 'mysql') {
            $problems[] = 'grid.database.driver must be mysql for a grid';
        }
        if ($creates) {
            // A grid with a name is a grid to make; with only a nick, it is the one the simulators join
            if ($databaseDriver === 'mysql') {
                foreach (['user', 'password'] as $key) {
                    if (trim((string) ($grid['database'][$key] ?? '')) === '') {
                        $problems[] = "grid.database.$key is required";
                    }
                }
            }
        }
        // A standalone is one simulator
        if ($standalone && count($data['simulators'] ?? []) > 1) {
            $problems[] = 'a standalone has one simulator (grid.standalone is true)';
        }
        foreach ($data['simulators'] ?? [] as $i => $simulator) {
            $simulatorDriver = strtolower(trim((string) ($simulator['database']['driver'] ?? '')));
            $simulatorDriver = $simulatorDriver !== '' ? $simulatorDriver : ($standalone ? $databaseDriver : 'mysql');
            if (!in_array($simulatorDriver, ['mysql', 'sqlite'], true)) {
                $problems[] = "simulators.$i.database.driver must be mysql or sqlite";
            } elseif ($simulatorDriver === 'mysql' && !$creates) {
                foreach (['user', 'password'] as $key) {
                    if (trim((string) ($simulator['database'][$key] ?? '')) === '') {
                        $problems[] = "simulators.$i.database.$key is required (the simulators of a grid that is not in the file)";
                    }
                }
            }
        }

        $owner = $data['owner'] ?? null;
        if (is_array($owner)) {
            if (Avatar::validateName($owner['name'] ?? '') !== null) {
                $problems[] = 'owner.name is a first and a last name, e.g. Jane Doe';
            }
            // The password, or its hash and salt as Robust keeps them (what the setup writes in its own file)
            $hashed = Avatar::validPasswordHash((string) ($owner['password_hash'] ?? ''), (string) ($owner['password_salt'] ?? ''));
            if (!$hashed && ($problem = Avatar::validatePassword($owner['password'] ?? '')) !== null) {
                $problems[] = "owner.password is $problem (or owner.password_hash and owner.password_salt)";
            }
            $email = trim((string) ($owner['email'] ?? ''));
            if ($email !== '' && !Avatar::validEmail($email)) {
                $problems[] = 'owner.email is an address, or nothing';
            }
        }

        if (isset($data['users'])) {
            $list = AccountList::parse((string) json_encode(['accounts' => $data['users']]), 'json');
            foreach ($list['errors'] as $error) {
                $problems[] = "users: $error";
            }
        }

        return $problems;
    }

    /**
     * The answers about the grid, by the names of its fields (Models\Grid::fields()): the ones the file gives.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function gridValues(array $data): array
    {
        return self::values(self::GRID, $data, []);
    }

    /**
     * The answers about a simulator, by the names of its fields (Models\Simulator::fields()). The database of a simulator is the one of
     * the grid, but its name (a simulator has its own).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function simulatorValues(array $data, int $i): array
    {
        $values = self::values(self::SIM, $data, ['$i' => (string) $i]);
        foreach (['host', 'user', 'password'] as $key) {
            if (!isset($values["database_$key"]) && ($grid = $data['grid']['database'][$key] ?? null) !== null) {
                $values["database_$key"] = (string) $grid;
            }
        }
        if (!isset($values['database_driver']) && isset($data['grid']['database']['driver'])) {
            $values['database_driver'] = (string) $data['grid']['database']['driver'];
        }

        return $values;
    }

    /**
     * Who owns the estate of a simulator: an account of the grid the file names (`estate.owner`), else the owner of the file, to make
     * if the grid does not have it (SimulatorPlanner::ownerAnswers()).
     *
     * @param array<string,mixed> $data
     * @return array{name:string,password:string,email:string}
     */
    public static function owner(array $data, int $i): array
    {
        $own = (string) ($data['simulators'][$i]['estate']['owner'] ?? '');
        $owner = is_array($data['owner'] ?? null) ? $data['owner'] : [];

        return [
            'name' => trim($own !== '' ? $own : (string) ($owner['name'] ?? '')),
            'password' => (string) ($owner['password'] ?? ''),
            'email' => (string) ($owner['email'] ?? ''),
        ];
    }

    /**
     * The answers about a region, by the names of its fields (Models\Region::fields()). The roles are the names of the flags.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function regionValues(array $data, int $i, int $j): array
    {
        $values = self::values(self::REGION, $data, ['$i' => (string) $i, '$j' => (string) $j]);
        if (isset($values['roles'])) {
            $values['roles'] = self::roles((array) $values['roles']);
        }

        return $values;
    }

    /**
     * What the setup does with the grid once it is made, and the administrator of its database server: the answers to the questions
     * of the application, which the file may give (the plan to accept, the instance to enable and to start, the credentials).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function gridPrompts(array $data): array
    {
        $answers = self::credentials($data['grid']['database'] ?? [], []);
        $answers += ['Enable grid' => self::yes($data, 'grid.enable'), 'Apply this configuration' => true];
        $answers += ['Start grid' => $answers['Enable grid'] && self::yes($data, 'grid.start')];

        return $answers;
    }

    /**
     * The same for a simulator.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function simulatorPrompts(array $data, int $i): array
    {
        $answers = self::credentials($data['simulators'][$i]['database'] ?? [], $data['grid']['database'] ?? []);
        $answers += [
            'Enable simulator' => self::yes($data, "simulators.$i.enable"),
            'Apply this configuration' => true,
        ];
        $answers += ['Start simulator' => $answers['Enable simulator'] && self::yes($data, "simulators.$i.start")];

        return $answers;
    }

    /**
     * The same for one more region of a simulator: it is loaded at once only when the simulator is to be enabled and started.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function regionPrompts(array $data, int $i): array
    {
        return [
            'Add region' => true,
            'Load it now' => self::yes($data, "simulators.$i.enable") && self::yes($data, "simulators.$i.start"),
        ];
    }

    /** @return array<string,string> the administrator of the database server: the one of the simulator, else the one of the grid */
    private static function credentials(array $own, array $grid): array
    {
        $answers = [];
        foreach (['admin_user' => 'Administrator user', 'admin_password' => 'Password of '] as $key => $question) {
            $value = $own[$key] ?? ($grid[$key] ?? null);
            if ($value !== null && $value !== '') {
                $answers[$question] = (string) $value;
            }
        }

        return $answers;
    }

    /** A yes or no of the file; yes unless it says no. */
    private static function yes(array $data, string $path): bool
    {
        $value = self::get($data, $path);

        return $value === null || $value === '' || !Result::isFalse($value);
    }

    /**
     * @param array<string,string> $fields field => path of its value in the file ($i, $j: places)
     * @param array<string,mixed> $data
     * @param array<string,string> $places
     * @return array<string,mixed>
     */
    private static function values(array $fields, array $data, array $places): array
    {
        $values = [];
        foreach ($fields as $field => $path) {
            $value = self::get($data, strtr($path, $places));
            if ($value !== null && $value !== '') {
                $values[$field] = is_array($value)
                    ? $value
                    : (is_bool($value)
                        ? $value
                        : (string) $value);
            }
        }

        return $values;
    }

    /**
     * The roles of a region: the names of the flags, or default, default_hg and fallback.
     *
     * @param array<int,mixed> $roles
     * @return list<string>
     */
    private static function roles(array $roles): array
    {
        $names = ['default' => 'DefaultRegion', 'default_hg' => 'DefaultHGRegion', 'fallback' => 'FallbackRegion'];

        return array_values(array_map(static fn($r): string => $names[strtolower((string) $r)] ?? (string) $r, $roles));
    }

    /** @param array<string,mixed> $data */
    private static function get(array $data, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($data) || !array_key_exists($key, $data)) {
                return null;
            }
            $data = $data[$key];
        }

        return $data;
    }

    /**
     * Put what the setup of a grid did in the setup file of the grid (created, or completed).
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function withGrid(array $data, GridPlan $plan): array
    {
        $grid = [
            'name' => $plan->gridName,
            'nick' => $plan->gridNick,
            'hostname' => $plan->baseHostname,
            'web_url' => $plan->webUrl,
            'hypergrid' => $plan->enableHypergrid,
            'standalone' => $plan->standalone,
            'helpers' => $plan->helpers,
            'helpers_path' => $plan->helpersPath,
            'search' => $plan->search,
            'offline' => $plan->offline,
            'economy' => $plan->economy,
            'options' => $plan->options,
            'web_server' => $plan->webServer,
            'web_site' => $plan->webSite,
            'public_port' => $plan->publicPort,
            'private_port' => $plan->privatePort,
            'console' => $plan->consoleMode,
            'console_port' => $plan->consolePort,
            'database' => [
                'driver' => $plan->dbDriver,
                'host' => $plan->dbHost,
                'name' => $plan->dbName,
                'user' => $plan->dbUser,
                'password' => $plan->dbPass,
            ],
        ];
        $data['version'] = 1;
        $data['grid'] = $grid;

        return $data;
    }

    /**
     * The same for a simulator and its first region, the owner when it was made.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function withSimulator(array $data, SimulatorPlan $plan): array
    {
        $simulator = [
            'name' => $plan->simulatorName,
            'http_port' => $plan->httpPort,
            'console' => $plan->consoleMode,
            'console_port' => $plan->consolePort,
            'database' => [
                'driver' => $plan->dbDriver,
                'host' => $plan->dbHost,
                'name' => $plan->dbName,
                'user' => $plan->dbUser,
                'password' => $plan->dbPass,
            ],
            'estate' => ['name' => $plan->estateName, 'owner' => $plan->estateOwner],
            'regions' => [],
        ];
        if ($plan->regionName !== '') {
            $simulator['regions'][] = array_filter(
                [
                    'name' => $plan->regionName,
                    'uuid' => $plan->existingRegionConfig !== '' ? $plan->regionUuid : null,
                    'source' => $plan->existingRegionConfig !== '' ? $plan->existingRegionConfig : null,
                    'location' => $plan->regionLocation,
                    'port' => $plan->regionPort,
                    'roles' => $plan->regionRoles ?: null,
                ],
                static fn($v): bool => $v !== null,
            );
        }

        // What the plan does not say (a region added later knows nothing of the database) is not erased
        $simulator = self::compact($simulator);
        $simulators = $data['simulators'] ?? [];
        $found = false;
        foreach ($simulators as $k => $existing) {
            if (($existing['name'] ?? '') === $plan->simulatorName) {
                // A region added later is kept with the ones this simulator already had
                $regions = $existing['regions'] ?? [];
                foreach ($simulator['regions'] ?? [] as $region) {
                    $regions = array_values(
                        array_filter($regions, static fn(array $r): bool => ($r['name'] ?? '') !== $region['name']),
                    );
                    $regions[] = $region;
                }
                $simulators[$k] = ['regions' => $regions] + array_replace_recursive($existing, $simulator);
                $found = true;
            }
        }
        if (!$found) {
            $simulators[] = $simulator;
        }
        $data['simulators'] = array_values($simulators);
        if ($plan->createOwner && $plan->estateOwner !== '') {
            // The password is kept hashed, as Robust keeps it: the hash and its salt are what makes the account
            $salt = md5(random_bytes(16));
            $data['owner'] = array_filter(
                [
                    'name' => $plan->estateOwner,
                    'password_hash' => AccountWriter::passwordHash($plan->ownerPassword, $salt),
                    'password_salt' => $salt,
                    'email' => $plan->ownerEmail,
                ],
                static fn(string $v): bool => $v !== '',
            );
        }
        $data['version'] = 1;

        return $data;
    }

    /**
     * Keep what a setup did in the setup file of the grid (`setup.json`, only for its owner: it holds the
     * passwords), to make the same grid again: `opensim import setup.json --apply`.
     *
     * @param \Closure(array<string,mixed>):array<string,mixed> $update  the file as it is, the file it becomes
     */
    public static function record(string $gridDir, \Closure $update): void
    {
        $path = "$gridDir/setup.json";
        $data = [];
        if (is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true) ?: [];
        }
        $umask = umask(0o027);
        $written = @file_put_contents($path, self::toJson($update($data)));
        umask($umask);
        if ($written !== false) {
            // Same rights as the ini files that hold the same passwords: the group of the install can read
            chmod($path, 0o640);
        }
    }

    /**
     * Without what is empty, at any depth.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function compact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value) && !array_is_list($value)) {
                $values[$key] = self::compact($value);
            }
            if ($values[$key] === '' || $values[$key] === []) {
                unset($values[$key]);
            }
        }

        return $values;
    }

    /** @param array<string,mixed> $data */
    public static function toJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** @param array<string,mixed> $data */
    public static function toYaml(array $data): string
    {
        return Yaml::dump($data, 6, 2);
    }
}
