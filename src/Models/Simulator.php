<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\InstanceSettings;
use OpenSim\Provisioning\GridAccounts;
use OpenSim\Provisioning\SimulatorMaster;
use OpenSim\Provisioning\Slug;

/**
 * A simulator: one process that runs one or several regions of a grid. It is its config (`sims/<name>.ini`) and the process
 * that runs from it: what the config says is asked of the simulator (ports, database, estate), whoever needs it.
 */
final class Simulator extends Instance
{
    /** @var ?array<string,mixed> what its own config says (see InstanceSettings) */
    private ?array $own = null;

    /**
     * The fields of a simulator, in the standard format of the forms (see Forms\FieldSet): what the setup asks to make or to change
     * one, and what any other form about a simulator shows. The groups are the screens of the setup.
     *
     * @param array{cores?:array<string,string>,modify?:bool,accounts?:?list<string>} $options the cores to run it (folder => name; with
     *        one there is no question), whether the simulator exists (its name stays), and the accounts of its grid that can own its
     *        estate (null: they are not known here, the owner is typed and has to exist; none: the owner is the first to create)
     * @return list<array<string,mixed>>
     */
    public static function fields(array $options = []): array
    {
        $cores = $options['cores'] ?? [];
        $accounts = $options['accounts'] ?? null;
        $fresh = static fn(array $field): array => $field + ['required' => true, 'type' => 'string'];

        $fields = [
            $fresh([
                'name' => 'name',
                'group' => 'simulator',
                'label' => _('Simulator name'),
                'callback' => [self::class, 'validateName'],
                'enable' => !($options['modify'] ?? false),
            ]),
            ['name' => 'core', 'group' => 'simulator', 'type' => 'select', 'label' => _('OpenSim core to run this simulator'), 'options' => $cores, 'enable' => count($cores) > 1],
            $fresh([
                'name' => 'http_port',
                'group' => 'simulator',
                'type' => 'integer',
                'label' => _('Simulator HTTP port'),
                'callback' => [Grid::class, 'validatePort'],
            ]),
            $fresh([
                'name' => 'external_host',
                'group' => 'simulator',
                'label' => _('Public address of this machine'),
                'description' => _('What the regions announce to the viewers (SYSTEMIP: the first interface)'),
            ]),
            [
                'name' => 'console',
                'group' => 'simulator',
                'type' => 'select',
                'label' => _('Console of the simulator'),
                'options' => ['rest' => _('Remote REST console (recommended)'), 'screen' => _('Screen session (on this machine)')],
            ],
            $fresh([
                'name' => 'console_port',
                'follows' => ['http_port'],
                'group' => 'simulator',
                'type' => 'integer',
                'label' => _('Console port'),
                'callback' => [Grid::class, 'validatePort'],
                'condition' => ['console' => 'rest'],
            ]),
            $fresh(['name' => 'database_host', 'group' => 'database', 'label' => _('Database host')]),
            $fresh(['name' => 'database_name', 'group' => 'database', 'label' => _('Database name')]),
            $fresh(['name' => 'database_user', 'group' => 'database', 'label' => _('Database user')]),
            $fresh(['name' => 'database_password', 'group' => 'database', 'label' => _('Database password')]),
            $fresh(['name' => 'estate_name', 'group' => 'estate', 'label' => _('Estate name')]),
        ];

        // The owner of the estate is an account of the grid, which OpenSimulator cannot make: it is chosen among the grid's, typed
        // when they are not known here, or made first
        if ($accounts === null) {
            $fields[] = $fresh([
                'name' => 'estate_owner',
                'group' => 'estate',
                'label' => _('Estate owner (an account of the grid)'),
                'callback' => [self::class, 'validateOwner'],
            ]);
        } elseif ($accounts !== []) {
            $fields[] = [
                'name' => 'estate_owner',
                'group' => 'estate',
                'type' => 'select',
                'label' => _('Estate owner'),
                'options' => array_combine($accounts, $accounts) + ['+' => _('Create a new account in the grid')],
            ];
        }
        $new = $accounts === null ? ['enable' => false] : ($accounts === [] ? [] : ['condition' => ['estate_owner' => '+']]);
        $fields[] = $fresh([
            'name' => 'new_name',
            'group' => 'estate',
            'label' => _('Name of the new account (First Last)'),
            'callback' => [self::class, 'validateOwner'],
        ] + $new);
        $fields[] = $fresh([
            'name' => 'new_password',
            'group' => 'estate',
            'type' => 'secret',
            'label' => _('Password of the new account'),
            'callback' => [self::class, 'validatePassword'],
        ] + $new);
        $fields[] = [
            // OpenSimulator does not need one, no more than the setup
            'name' => 'new_email',
            'group' => 'estate',
            'type' => 'string',
            'required' => false,
            'label' => _('Email of the new account (optional)'),
            'callback' => [self::class, 'validateEmail'],
        ] + $new;

        return $fields;
    }

    /** The name of a simulator. An accent is not refused, it is transliterated (Noël becomes Noel). */
    public static function validateName(mixed $value): ?string
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]*$/', trim(Slug::ascii((string) $value))) === 1
            ? null
            : _('Letters, digits, spaces, _ and - only.');
    }

    /** The name of an account: first and last name. */
    public static function validateOwner(mixed $value): ?string
    {
        return GridAccounts::validName(trim((string) $value)) ? null : _('First and last name, e.g. Jane Doe.');
    }

    /** The password of an account to make. */
    public static function validatePassword(mixed $value): ?string
    {
        return preg_match('/^[^"\r\n]{6,}$/', (string) $value) === 1 ? null : _('At least 6 characters, without double quote.');
    }

    /** The email of an account to make, or nothing. */
    public static function validateEmail(mixed $value): ?string
    {
        return trim((string) $value) === '' || preg_match('/^[^\s"\'\\\\]+@[^\s"\'\\\\]+$/', trim((string) $value)) === 1
            ? null
            : _('An email address, or nothing.');
    }

    public function type(): string
    {
        return 'opensim';
    }

    public function assembly(): string
    {
        return 'OpenSim';
    }

    public static function enabledFolder(): string
    {
        return 'opensim.d';
    }

    protected function consolePortKeys(): array
    {
        return ['console_port', 'consoleport'];
    }

    protected function configFiles(): array
    {
        $grid = $this->gridDirectory();

        return $grid !== null && SimulatorMaster::exists($grid) ? [SimulatorMaster::path($grid), $this->ini()] : [$this->ini()];
    }

    /**
     * The master of its grid, made again when the core or the grid changed, null when the grid has none (its simulators have
     * their whole config).
     */
    public function master(string $binDir): ?string
    {
        $grid = $this->gridDirectory();
        if ($grid === null || !SimulatorMaster::exists($grid)) {
            return null;
        }
        (new SimulatorMaster())->ensure($grid, $binDir);
        $this->forget();

        return SimulatorMaster::path($grid);
    }

    /** Whether its config is there. */
    public function exists(): bool
    {
        return is_file($this->ini());
    }

    protected function forget(): void
    {
        parent::forget();
        $this->own = null;
    }

    /** What its own file says, in the names of InstanceSettings; nothing for a simulator that is not made yet. */
    private function own(string $key): mixed
    {
        return ($this->own ??= $this->exists() ? InstanceSettings::of($this->ini()) : [])[$key] ?? null;
    }

    /** The port it listens on, null when its config does not say it. */
    public function httpPort(): ?int
    {
        return $this->own('httpPort');
    }

    /** The port of its remote console, null when its config has none (it runs in a screen session). */
    public function consolePort(): ?int
    {
        return $this->own('consolePort');
    }

    /** The host clients reach its remote console by. */
    public function consoleHost(): ?string
    {
        return $this->own('consoleHost');
    }

    public function consoleUser(): ?string
    {
        return $this->own('consoleUser');
    }

    public function consolePassword(): ?string
    {
        return $this->own('consolePass');
    }

    /** The database of its regions, null when its config does not say it. */
    public function databaseHost(): ?string
    {
        return $this->own('dbHost');
    }

    public function databaseName(): ?string
    {
        return $this->own('dbName');
    }

    public function databaseUser(): ?string
    {
        return $this->own('dbUser');
    }

    public function databasePassword(): ?string
    {
        return $this->own('dbPass');
    }

    public function estateName(): ?string
    {
        return $this->own('estateName');
    }

    public function estateOwner(): ?string
    {
        return $this->own('estateOwner');
    }

    /**
     * Every fact about it, the password of its database and of its console left out.
     *
     * @return array<string,mixed>
     */
    public function detail(): array
    {
        return [
            'name' => $this->name(),
            'grid' => $this->grid()?->nick(),
            'ini' => $this->ini(),
            'link' => $this->link(),
            'enabled' => $this->isEnabled(),
            'running' => $this->isRunning(),
            'httpPort' => $this->httpPort(),
            'consolePort' => $this->consolePort(),
            'console' => $this->remoteConsole() !== null ? 'rest' : 'screen',
            'databaseHost' => $this->databaseHost(),
            'databaseName' => $this->databaseName(),
            'databaseUser' => $this->databaseUser(),
            'estateName' => $this->estateName(),
            'estateOwner' => $this->estateOwner(),
            'regionsDirectory' => $this->regionsDirectory() ?: null,
            'core' => $this->core()?->directory(),
        ];
    }

    /** The folder of the descriptions of its regions, empty when its config does not say where they are. */
    public function regionsDirectory(): string
    {
        return rtrim($this->launchSetting('regionload_regionsdir'), '/');
    }

    /** Whether it has a region to load: a region file in its regions folder. One without waits for the answers of a region to create. */
    public function hasRegion(): bool
    {
        $dir = $this->regionsDirectory();

        // A simulator that does not say where its regions are (one made by hand) is left alone
        return $dir === '' || glob("$dir/*.ini") !== [];
    }
}
