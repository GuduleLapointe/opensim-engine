<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Forms\FieldSet;
use OpenSim\Runtime\Core;
use OpenSim\Models\Grid;
use OpenSim\Models\Simulator;
use OpenSim\Network\Ports;
use OpenSim\Runtime\Environment;
use OpenSim\Config\GridConfig;

/**
 * From the answers about a simulator to its plan. The answers are the fields of Simulator::fields(), by name (its screens: the
 * simulator, its database, its estate); the first region is the business of RegionPlanner. What is missing is what the setup
 * proposes: the first block of ports free for the simulators of the grid, the account of the other simulators of the grid for the
 * database, the estate named after the grid, or the simulator as it is when it is changed.
 *
 * Nothing is asked and nothing is written here: resolve() says what the simulator would be, problems() what is wrong with it, plan()
 * gives the SimulatorPlan that SimulatorProvisioner makes.
 */
final class SimulatorPlanner
{
    /** @var array<string,mixed> */
    private array $context;

    /** @var array<string,string> what was made at random, kept so that what is proposed is what is made */
    private array $secrets = [];

    private function __construct(array $context)
    {
        $this->context = $context + self::context();
    }

    /**
     * What the planner knows of the grid, the machine and the simulator.
     *
     * @return array<string,mixed>
     */
    public static function context(): array
    {
        return [
            'grid' => null, // the Grid of the grid it joins (one that is only planned is fine)
            'cores' => [], // folder => name
            'modify' => false, // the simulator is named by the caller: it exists and its name stays
            'simulatorName' => '', // that name
            'defaultName' => 'Simulator1',
            'block' => 9000, // the first block of ten ports free for the simulators of the grid
            'current' => null, // the simulator, when it exists: its settings are the ones proposed
            'sibling' => null, // another simulator of the grid, whose database account is proposed
            'accounts' => null, // the accounts of the grid, null when they are not known here
            'nextPort' => null, // Closure(int $min): int, the first free port from there (default: Ports::next)
            'random' => null, // Closure(int $length, bool $letters): string (default: random_int)
        ];
    }

    /**
     * The planner for a simulator of this grid.
     *
     * @param ?string $simulatorName the simulator to change, null for a new one
     * @param ?string $regionName the region the simulator is made for, which gives it its name
     */
    public static function forGrid(
        Grid $grid,
        DatabaseService $database,
        UiInterface $ui,
        ?string $simulatorName = null,
        ?string $regionName = null,
    ): self {
        $planner = new self([
            'grid' => $grid,
            'cores' => Core::choices($grid->environment()->layout->coreRoot()),
            'modify' => $simulatorName !== null,
            'simulatorName' => (string) $simulatorName,
        ]);
        // The first simulator of a grid has the name of its landing region, that is its first region; an accent is
        // transliterated (Noël becomes Noel)
        $firstSimulator =
            !$grid->isRemote() && Grid::at($grid->environment(), $grid->directory())->simulatorNames() === [];
        $taken = static fn(string $v): ?string => Simulator::validateName($v) ??
            (is_file(GridConfig::simulatorsDirectory($grid->directory()) . '/' . $grid->instanceOf($v) . '.ini')
                ? 'taken'
                : null);

        // The ports of a simulator are a block of ten, the first free one from where the simulators of the grid start (9000 for
        // Robust on 8002), on this machine and among the regions the grids of this machine know on the others
        $foreign = (new GridRegistry($database))->knownPorts($grid->environment(), $grid);

        $context = [
            'defaultName' =>
                $simulatorName ?? SimulatorName::suggest($regionName, $firstSimulator ? 'Welcome' : null, $taken),
            'block' => (new Ports($grid->environment()))->nextBlock(
                Ports::simulatorsFrom($grid->publicPort()),
                $foreign,
            ),
            // A standalone that has no account yet has no database to ask: the owner is made at its first start
            'accounts' => $grid->isRemote()
                ? null
                : (new GridAccounts($database, $ui))->names($grid) ?? ($grid->isStandalone() ? [] : null),
        ];

        return $planner->with($context)->forSimulator($simulatorName ?? $context['defaultName']);
    }

    /** A planner with this context, for the tests and for the callers that know it. */
    public static function fromContext(array $context): self
    {
        return new self($context);
    }

    /** The same planner with more context. */
    public function with(array $context): self
    {
        return new self($context + $this->context);
    }

    /** The same planner, starting from the simulator of this name: its settings are the ones proposed, as is the account of the others. */
    public function forSimulator(string $name): self
    {
        $grid = $this->context['grid'];
        $slug = $grid->instanceOf($name);
        $ini = GridConfig::simulatorsDirectory($grid->directory()) . "/$slug.ini";
        $environment = $grid->environment();
        $sibling = null;
        foreach (glob(GridConfig::simulatorsDirectory($grid->directory()) . '/*.ini') ?: [] as $other) {
            if (basename($other, '.ini') === $slug || $sibling !== null) {
                continue;
            }
            $found = new Simulator($environment, $other);
            if (($found->databaseUser() ?? '') !== '' && ($found->databasePassword() ?? '') !== '') {
                // The account of the first simulator of the grid is the one of the next ones, and so is its password
                $sibling = $found;
            }
        }

        return $this->with([
            'current' => is_file($ini) ? new Simulator($environment, $ini) : null,
            'sibling' => $sibling,
        ]);
    }

    /** The cores that can run the simulator (folder => name). */
    public function cores(): array
    {
        return $this->context['cores'];
    }

    /** The accounts of the grid that can own the estate; null when they are not known here. */
    public function accounts(): ?array
    {
        return $this->context['accounts'];
    }

    /** The fields of the simulator, for this grid and this machine. */
    public function fields(): FieldSet
    {
        return new FieldSet(
            Simulator::fields([
                'cores' => $this->context['cores'],
                'modify' => $this->context['modify'],
                'accounts' => $this->context['accounts'],
                'standalone' => $this->context['grid']->isStandalone(),
            ]),
        );
    }

    /**
     * What the simulator is for these answers: each answer given, else what is proposed.
     *
     * @param array<string,mixed> $answers by the names of the fields
     * @return array<string,mixed> name, slug, core, http_port, external_host, console, console_port, database_*, estate_name, estate_owner,
     *         new_name, new_password, new_email
     */
    public function resolve(array $answers = []): array
    {
        $c = $this->context;
        $grid = $c['grid'];
        $current = $c['current'];
        $sibling = $c['sibling'];
        $text = static fn(string $name, mixed $default): string => trim((string) ($answers[$name] ?? $default));

        $name = $c['modify'] ? $c['simulatorName'] : Slug::ascii($text('name', $c['defaultName']));
        $slug = $grid->instanceOf($name);
        $cores = $c['cores'];
        $core = $text('core', count($cores) > 1 ? $grid->coreDirectory() : '');
        $core =
            $core !== ''
                ? $core
                : (string) ($grid->coreDirectory() !== '' && isset($cores[$grid->coreDirectory()])
                    ? $grid->coreDirectory()
                    : array_key_first($cores));

        // A standalone serves the grid itself: its port is the one of the grid
        $http0 = (int) ($current?->httpPort() ?? ($grid->isStandalone() ? $grid->publicPort() : $c['block']));
        $http = (int) ($answers['http_port'] ?? $http0);
        $console0 = (int) ($current?->consolePort() ?? $this->consoleFor($http0));
        $owner =
            $c['accounts'] === null
                ? $text('estate_owner', $current?->estateOwner() ?? '')
                : ($c['accounts'] === []
                    ? '+'
                    : $text(
                        'estate_owner',
                        $current?->estateOwner() !== null && in_array($current->estateOwner(), $c['accounts'], true)
                            ? $current->estateOwner()
                            : $c['accounts'][0],
                    ));

        return [
            'name' => $name,
            'slug' => $slug,
            'core' => $core,
            'http_port' => $http,
            'external_host' => $text('external_host', 'SYSTEMIP'),
            'console' => $text('console', $current === null || $current->consoleUser() !== null ? 'rest' : 'screen'),
            'console_port' =>
                (int) ($answers['console_port'] ?? ($http === $http0 ? $console0 : $this->consoleFor($http))),
            'database_host' => $text(
                'database_host',
                $current?->databaseHost() ?? ($sibling?->databaseHost() ?? $grid->databaseHost()),
            ),
            // The database of a standalone is the one of the grid
            'database_name' => $text(
                'database_name',
                $current?->databaseName() ?? ($grid->isStandalone() ? $grid->databaseName() : Slug::snake($slug)),
            ),
            'database_user' => $text(
                'database_user',
                $current?->databaseUser() ?? ($sibling?->databaseUser() ?? $grid->databaseUser()),
            ),
            'database_password' => $text(
                'database_password',
                $current?->databasePassword() ??
                    ($sibling?->databasePassword() ??
                        ($grid->databasePassword() !== ''
                            ? $grid->databasePassword()
                            : $this->secret('database_password', 20, false))),
            ),
            'estate_name' => $text('estate_name', $current?->estateName() ?? "{$grid->name()} Estate"),
            'estate_owner' => $owner,
            'new_name' => $text('new_name', ''),
            'new_password' => (string) ($answers['new_password'] ?? ''),
            'new_email' => $text('new_email', ''),
        ];
    }

    /**
     * The answers about the owner of the estate when a file names it: an account of the grid if the grid has it (or if its accounts
     * are not known here: it has to exist), else a new account to make with the name, the password and the email of the file.
     *
     * @param array{name?:string,password?:string,email?:string} $owner
     * @return array<string,string>
     */
    public function ownerAnswers(array $owner): array
    {
        $name = trim((string) ($owner['name'] ?? ''));
        if ($name === '') {
            return [];
        }
        $accounts = $this->context['accounts'];
        if ($accounts === null || in_array($name, $accounts, true)) {
            return ['estate_owner' => $name];
        }

        return [
            'estate_owner' => '+',
            'new_name' => $name,
            'new_password' => (string) ($owner['password'] ?? ''),
            'new_email' => (string) ($owner['email'] ?? ''),
        ];
    }

    /**
     * What is wrong with these answers, by field.
     *
     * @return array<string,string>
     */
    public function problems(array $answers = []): array
    {
        $values = $this->resolve($answers);
        $problems = $this->fields()->problems(array_intersect_key($answers, $values) + $values);
        $grid = $this->context['grid'];
        // A standalone is one simulator: no other can join it
        if (
            $grid->isStandalone() &&
            !$this->context['modify'] &&
            Grid::at($grid->environment(), $grid->directory())->simulatorNames() !== []
        ) {
            $problems['name'] = _('A standalone has one simulator, and it has it.');
        }

        return $problems;
    }

    /** The plan of the simulator these answers describe. The region is added by RegionPlanner::apply(). */
    public function plan(array $answers = []): SimulatorPlan
    {
        $v = $this->resolve($answers);
        $grid = $this->context['grid'];
        $current = $this->context['current'];

        $plan = new SimulatorPlan();
        $plan->gridNick = $grid->nick();
        $plan->gridName = $grid->name();
        $plan->gridDir = $grid->directory();
        $plan->hypergrid = $grid->isHypergrid();
        $plan->standalone = $grid->isStandalone();
        $plan->baseHostname = $grid->baseHostname();
        $plan->publicPort = $grid->publicPort();
        $plan->privatePort = $grid->privatePort();
        $plan->logsDirectory = $grid->logsDirectory();
        $plan->gridHere = !$grid->isRemote();
        // A remote grid not kept yet is written with the simulator
        $plan->remoteGrid =
            $grid->isRemote() && !is_file("{$grid->directory()}/{$grid->nick()}.conf") ? $grid->describe() : null;
        // A grid joined by a preset: the simulators read the files of the preset, where they are
        $plan->preset = $grid->preset();
        $plan->configDir =
            $grid->preset() !== ''
                ? (new Presets([], $grid->environment()->layout->etcRoot()))->dir($grid->preset())
                : '';

        $plan->simulatorName = $v['name'];
        $plan->slug = $v['slug'];
        $plan->dataDirectory = "{$grid->dataDirectory()}/{$plan->slug}";
        $plan->cacheDirectory = "{$grid->cacheDirectory()}/{$plan->slug}";
        $plan->coreDirectory = $v['core'];
        $plan->httpPort = $v['http_port'];
        $plan->externalHost = $v['external_host'];
        $plan->consoleMode = $v['console'];
        // The console port is in the block of the simulator, kept even when the console is a screen one
        $plan->consolePort = $this->consoleFor($plan->httpPort);
        if ($plan->consoleMode === 'rest') {
            $plan->consolePort = $v['console_port'];
            // As the helpers make theirs: 12 lower case letters, 32 letters and digits; the host clients reach it by is the public
            // address of the machine, when it is known
            $plan->consoleHost =
                (string) ($current?->consoleHost() ?? ($plan->externalHost !== 'SYSTEMIP' ? $plan->externalHost : ''));
            $plan->consoleUser = (string) ($current?->consoleUser() ?? $this->secret('console_user', 12, true));
            $plan->consolePass =
                (string) ($current?->consolePassword() ?? $this->secret('console_password', 32, false));
        }

        $plan->dbHost = $v['database_host'];
        $plan->dbName = $v['database_name'];
        $plan->dbUser = $v['database_user'];
        $plan->dbPass = $v['database_password'];

        // What the grid decides for its simulators (its options), when their config is whole: with a master it is the master's
        $plan->optionsLayer = SimulatorMaster::exists($grid->directory()) ? '' : (new GridOptions($grid))->layer();

        $plan->estateName = $v['estate_name'];
        if ($v['estate_owner'] === '+') {
            $plan->estateOwner = $v['new_name'];
            $plan->createOwner = true;
            $plan->ownerPassword = $v['new_password'];
            $plan->ownerEmail = $v['new_email'];
            $plan->ownerUuid = RegionPlanner::uuid();
        } else {
            $plan->estateOwner = $v['estate_owner'];
        }

        return $plan;
    }

    /** The console of a simulator is the x4 of its block of ten ports. */
    private function consoleFor(int $http): int
    {
        $base = Ports::simulatorBlock($http);

        return $base !== null ? $base + 4 : $this->nextPort($http + 1);
    }

    private function nextPort(int $min): int
    {
        return $this->context['nextPort'] !== null
            ? $this->context['nextPort']($min)
            : (new Ports($this->context['grid']->environment()))->next($min);
    }

    /** Something made at random, once. */
    private function secret(string $key, int $length, bool $letters): string
    {
        if (!isset($this->secrets[$key])) {
            $this->secrets[$key] =
                $this->context['random'] !== null
                    ? $this->context['random']($length, $letters)
                    : self::randomString(
                        $length,
                        $letters
                            ? 'abcdefghijklmnopqrstuvwxyz'
                            : 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
                    );
        }

        return $this->secrets[$key];
    }

    private static function randomString(int $length, string $alphabet): string
    {
        $string = '';
        for ($i = 0; $i < $length; $i++) {
            $string .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $string;
    }
}
