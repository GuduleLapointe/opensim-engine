<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Result;
use OpenSim\Config\ConfigReader;
use OpenSim\Forms\FieldSet;
use OpenSim\Runtime\Core;
use OpenSim\Models\Grid;
use OpenSim\Network\Ports;
use OpenSim\Provisioning\Web\HelpersConfig;
use OpenSim\Provisioning\Web\Services;
use OpenSim\Provisioning\Web\Snippets;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\ShellService;

/**
 * From the answers about a grid to its plan. The answers are the fields of Grid::fields(), by name, from whoever asked them (the
 * terminal, a web page, a setup file, an API): the ones that are missing take the value the setup proposes, which follows the
 * answers that were given (the private port follows the public one, the console port both, the center the port, the web address the
 * host, the nick the name, the name of the database the nick), or the grid as it is when it is changed.
 *
 * Nothing is asked and nothing is written here: resolve() says what the grid would be, problems() what is wrong with it, plan() gives
 * the GridPlan that GridProvisioner makes.
 */
final class GridPlanner
{
    /** @var array<string,mixed> */
    private array $context;

    /** @var array<string,string> what was made at random, kept so that what is proposed is what is made */
    private array $secrets = [];

    /** @param array<string,mixed> $context see context() */
    private function __construct(array $context)
    {
        $this->context = $context + self::context();
    }

    /**
     * What the planner knows of the machine and of the grid.
     *
     * @return array<string,mixed>
     */
    public static function context(): array
    {
        return [
            'environment' => null, // the Environment of the machine (its layout gives the roots, the core and the data source)
            'cores' => [], // folder => name of the cores that can run the grid
            'modify' => false, // the grid exists and is changed: its nick stays
            'nick' => '', // the grid whose settings are the starting point
            'current' => null, // its Robust, when it exists: its settings are the ones proposed
            'currentHypergrid' => null,
            'currentStandalone' => false, // the grid exists and is a standalone: no Robust, one simulator
            'currentGrid' => null, // the grid that exists, which says what a standalone has in place of a Robust
            'savedSpacing' => 0,
            'savedCenter' => '',
            'defaultName' => 'My',
            'defaultHost' => 'localhost',
            'defaultPublic' => 8002,
            'helpersAvailable' => false,
            'helpersPath' => '/helpers',
            'webServer' => 'caddy', // the web server the machine has
            'nextPort' => null, // Closure(int $min): int, the first free port from there (default: Ports::next)
            'random' => null, // Closure(int $length, bool $letters): string (default: random_int)
        ];
    }

    /** The planner of a machine, for a new grid or for the grid of this nick. */
    public static function forMachine(Environment $environment, ?string $modifyNick = null): self
    {
        $planner = new self([
            'environment' => $environment,
            'cores' => Core::choices($environment->layout->coreRoot()),
            'modify' => $modifyNick !== null,
            'defaultName' => self::defaultName(),
            'defaultHost' => self::defaultHost(),
            'defaultPublic' => self::defaultPublicPort($environment),
            // The helpers are proposed when the machine has them; their path is the default one until a grid says another
            'helpersAvailable' => is_dir(Snippets::WEBROOT),
            'helpersPath' => HelpersConfig::basePath('/nonexistent'),
            'webServer' => self::defaultWebServer(),
        ]);

        return $modifyNick !== null ? $planner->forNick($modifyNick) : $planner;
    }

    /** A planner with this context, for the tests and for the callers that know it. */
    public static function with(array $context): self
    {
        return new self($context);
    }

    /** The same planner, starting from the grid of this nick: its settings are the ones proposed. */
    public function forNick(string $nick): self
    {
        $dir = rtrim($this->environment()->layout->etcRoot(), '/') . "/grids/$nick";
        $grid = Grid::find($this->environment(), $nick);
        $center = ConfigReader::file("$dir/$nick.conf")['Grid']['Center'] ?? '';

        return new self(
            [
                'nick' => $nick,
                'current' => $grid?->robust(),
                'currentHypergrid' => $grid?->robust() !== null ? $grid->isHypergrid() : null,
                'currentStandalone' => $grid?->isStandalone() ?? false,
                'currentGrid' => $grid,
                'savedSpacing' => $grid?->regionSpacing() ?? 0,
                'savedCenter' => trim((string) $center, " \t\""),
                'helpersAvailable' => is_file(HelpersConfig::path($dir)) || is_dir(Snippets::WEBROOT),
                'helpersPath' => HelpersConfig::basePath($dir),
            ] + $this->context,
        );
    }

    /** The host name a grid is proposed: the one of the machine. */
    public static function defaultHost(): string
    {
        return Grid::normalizeHostname(ShellService::capture('hostname -f')[1]) ?: 'localhost';
    }

    /** The web server a grid is proposed: the first the machine has (Caddy, nginx, Apache), else Caddy. */
    public static function defaultWebServer(): string
    {
        foreach (['caddy' => 'caddy', 'nginx' => 'nginx', 'apache' => 'apache2'] as $server => $program) {
            if (trim(ShellService::capture('command -v ' . $program)[1]) !== '') {
                return $server;
            }
        }

        return 'caddy';
    }

    /** The public port a grid is proposed: the one of the first block of ten ports that is free, ending with 2. */
    public static function defaultPublicPort(Environment $environment): int
    {
        return (new Ports($environment))->nextBlock(8000) + 2;
    }

    /** The name a grid is proposed: the one of the machine. */
    public static function defaultName(): string
    {
        [, $host] = ShellService::capture('hostname -s');
        $short = trim($host);
        if ($short === '') {
            [, $fqdn] = ShellService::capture('hostname -f');
            $short = strtok(trim($fqdn), '.') ?: 'My';
        }

        return ucfirst($short);
    }

    /** The cores that can run the grid (folder => name). */
    public function cores(): array
    {
        return $this->context['cores'];
    }

    /** The fields of the grid, for this machine. */
    public function fields(): FieldSet
    {
        return new FieldSet(Grid::fields(['cores' => $this->context['cores'], 'modify' => $this->context['modify']]));
    }

    /**
     * What the grid is for these answers: each answer given, else what is proposed.
     *
     * @param array<string,mixed> $answers by the names of the fields
     * @return array<string,mixed> name => value (ports and spacing are numbers, hypergrid and helpers are true or false)
     */
    public function resolve(array $answers = []): array
    {
        $c = $this->context;
        $current = $c['current'];
        $layout = $this->environment()->layout;
        $modify = $c['modify'];
        $text = static fn(string $name, mixed $default): string => trim((string) ($answers[$name] ?? $default));

        // A standalone that exists has no Robust: its description says what Robust would
        $solo = $c['currentStandalone'] ? $c['currentGrid'] : null;
        $standalone = Result::isTrue($answers['standalone'] ?? $c['currentStandalone']);

        $name = $text(
            'name',
            $current?->gridName() ?? ($solo?->name() ?? ($modify ? Slug::nameOf($c['nick']) : $c['defaultName'])),
        );
        $nick = $modify ? $c['nick'] : $text('nick', Slug::nick($name));
        $hypergrid = Result::isTrue($answers['hypergrid'] ?? ($c['currentHypergrid'] ?? true));
        $spacing = (int) ($answers['spacing'] ?? $c['savedSpacing']);
        $cores = $c['cores'];
        $core = $text('core', count($cores) > 1 ? $layout->coreDirectory() : '');
        $core = $core !== '' ? $core : (string) array_key_first($cores);

        $host0 = (string) ($current?->baseHostname() ?? ($solo?->baseHostname() ?? $c['defaultHost']));
        $host = Grid::normalizeHostname($text('hostname', $host0));
        $public0 = (int) ($current?->publicPort() ?? ($solo?->publicPort() ?? $c['defaultPublic']));
        $public = (int) ($answers['public_port'] ?? $public0);
        $private0 = (int) ($current?->privatePort() ?? $this->privateFor($public0));
        $private = (int) ($answers['private_port'] ?? ($public === $public0 ? $private0 : $this->privateFor($public)));
        $console0 = (int) ($current?->consolePort() ?? $this->consoleFor($public0, $private0));
        $consolePort =
            (int) ($answers['console_port'] ??
                ($public === $public0 ? $console0 : $this->consoleFor($public, $private)));
        $center = $text('center', $c['savedCenter'] !== '' ? $c['savedCenter'] : "$public,$public");
        $web0 = (string) ($current?->webUrl() ?? "https://$host0");
        $web = $text('web_url', $host === $host0 ? $web0 : "https://$host");

        $foundPassword = (string) ($current?->databasePassword() ?? ($solo?->databasePassword() ?? ''));

        $values = [
            'name' => $name,
            'nick' => $nick,
            'hypergrid' => $hypergrid,
            'standalone' => $standalone,
            'spacing' => $spacing,
            'core' => $core,
            'hostname' => $host,
            'public_port' => $public,
            'private_port' => $private,
            'center' => $center,
            'console' => $text('console', $current === null || $current->consoleUser() !== null ? 'rest' : 'screen'),
            'console_port' => $consolePort,
            'web_url' => $web,
            'helpers' => Result::isTrue($answers['helpers'] ?? $c['helpersAvailable']),
            'helpers_path' => $text('helpers_path', $c['helpersPath']),
            // Without the helpers the grid has no search of its own
            'search' => self::choice(
                $answers['search'] ?? null,
                ['internal', 'shared', 'disabled'],
                Result::isTrue($answers['helpers'] ?? $c['helpersAvailable']) ? 'internal' : 'disabled',
            ),
            // Without the helpers, Robust keeps the messages
            'offline' => self::choice(
                $answers['offline'] ?? null,
                ['helpers', 'robust'],
                Result::isTrue($answers['helpers'] ?? $c['helpersAvailable']) ? 'helpers' : 'robust',
            ),
            'economy' => self::choice($answers['economy'] ?? null, ['beta', 'gloebit'], 'beta'),
            'ossl' => Result::isTrue($answers['ossl'] ?? $this->optionDefault('ossl')),
            'web_server' => self::choice($answers['web_server'] ?? null, ['caddy', 'apache', 'nginx'], $c['webServer']),
            'web_site' => self::choice($answers['web_site'] ?? null, ['helpers', 'website'], 'helpers'),
            'database_host' => $text(
                'database_host',
                $current?->databaseHost() ?? ($solo?->databaseHost() ?? ($layout->dataSource() ?: 'localhost')),
            ),
            // The database of a standalone is the one of the grid, no Robust is in its name
            'database_name' => $text(
                'database_name',
                $current?->databaseName() ??
                    ($solo?->databaseName() ?? Slug::snake($nick) . ($standalone ? '' : '_robust')),
            ),
            'database_user' => $text(
                'database_user',
                $current?->databaseUser() ?? ($solo?->databaseUser() ?? 'opensim'),
            ),
            // A password found is reused, otherwise one is made (never changeme)
            'database_password' => $text(
                'database_password',
                $foundPassword !== '' && $foundPassword !== 'changeme'
                    ? $foundPassword
                    : $this->secret('database_password', 20, false),
            ),
        ];
        // An account that another grid of this machine already uses keeps its password: it is the one the server has
        if (!$modify && !isset($answers['database_password']) && $foundPassword === '') {
            $values['database_password'] =
                $this->knownPassword($values['database_host'], $values['database_user']) ?? $values['database_password'];
        }

        return $values;
    }

    /** The password the grids of this machine use for an account of a database server, null when none does. */
    private function knownPassword(string $host, string $user): ?string
    {
        $environment = $this->environment();
        foreach (\OpenSim\Archives\Instances::grids($environment->layout->etcRoot()) as $nick) {
            $grid = Grid::find($environment, $nick);
            $password = $grid?->databasePassword() ?? '';
            if ($grid !== null && $grid->databaseHost() === $host && $grid->databaseUser() === $user && $password !== '' && $password !== 'changeme') {
                return $password;
            }
        }

        return null;
    }

    /**
     * What is wrong with these answers, by field.
     *
     * @return array<string,string>
     */
    public function problems(array $answers = []): array
    {
        // What was answered is checked as it was given (a port that is not a number is not 0), the rest as it is proposed
        $values = $this->resolve($answers);

        return $this->fields()->problems(array_intersect_key($answers, $values) + $values);
    }

    /** The plan of the grid these answers describe. */
    public function plan(array $answers = []): GridPlan
    {
        $v = $this->resolve($answers);
        $layout = $this->environment()->layout;
        $current = $this->context['current'];
        $nick = (string) $v['nick'];
        $gridDir = rtrim($layout->etcRoot(), '/') . "/grids/$nick";

        $plan = new GridPlan();
        $plan->gridName = $v['name'];
        $plan->gridNick = $nick;
        $plan->gridSlug = Slug::slug($v['name']);
        $plan->gridDir = $gridDir;
        $plan->etcDirectory = $gridDir;
        $plan->dataDirectory = $layout->dataRoot() . "/$nick";
        $plan->cacheDirectory = $layout->cacheRoot() . "/$nick";
        $plan->logsDirectory = $layout->logsRoot();
        $plan->enableHypergrid = $v['hypergrid'];
        $plan->standalone = $v['standalone'];
        // The rule that places the regions: free blocks between them
        $plan->regionSpacing = $v['spacing'];
        $plan->coreDirectory = $v['core'];
        $plan->binDir = $plan->coreDirectory . '/bin';

        $plan->baseHostname = $v['hostname'];
        $plan->publicPort = $v['public_port'];
        // A standalone is served by one process, on one port
        $plan->privatePort = $plan->standalone ? $v['public_port'] : $v['private_port'];
        $plan->center = $v['center'];
        $plan->webUrl = $v['web_url'];
        $plan->helpers = $v['helpers'];
        if ($plan->helpers) {
            // The URL of the web site is given, the path after it is the choice of the operator
            $plan->helpersPath = Services::normalize($v['helpers_path']);
        }
        $plan->search = $v['search'];
        $plan->offline = $v['offline'];
        $plan->economy = $v['economy'];
        $plan->ossl = $v['ossl'];
        $plan->webServer = $v['web_server'];
        $plan->webSite = $v['web_site'];
        $plan->consoleMode = $v['console'];
        if ($plan->consoleMode === 'rest') {
            $plan->consolePort = $v['console_port'];
            // As the helpers make theirs: 12 lower case letters, 32 letters and digits
            $plan->consoleHost = (string) ($current?->consoleHost() ?? $plan->baseHostname);
            $plan->consoleUser = (string) ($current?->consoleUser() ?? $this->secret('console_user', 12, true));
            $plan->consolePass =
                (string) ($current?->consolePassword() ?? $this->secret('console_password', 32, false));
        }
        $plan->dbHost = $v['database_host'];
        $plan->dbName = $v['database_name'];
        $plan->dbUser = $v['database_user'];
        $plan->dbPass = $v['database_password'];
        // The options: the ones asked for, else the ones the grid has when it is changed, else the ones that are on by default and
        // that the grid can have
        $plan->options = (new GridOptions(Grid::planned($this->environment(), $plan)))->selection(
            $answers,
            $this->context['currentGrid']?->options(),
        );
        $plan->ossl = in_array('ossl-extended', $plan->options, true);

        return $plan;
    }

    /** Whether the option that takes this answer is on by default (see OptionTemplate). */
    private function optionDefault(string $answer): bool
    {
        foreach ((new Presets([], $this->environment()->layout->etcRoot()))->options() as $option) {
            if ($option->group() === '' && $option->answer() === $answer) {
                return $option->default();
            }
        }

        return false;
    }

    /** The ports of an instance are a block of ten, the first free one: the public one ends with 2, the private one with 3. */
    private function privateFor(int $public): int
    {
        return $public % 10 === 2 ? $public + 1 : $this->nextPort($public + 1);
    }

    /** The console ends with 4. */
    private function consoleFor(int $public, int $private): int
    {
        return $public % 10 === 2 ? $public + 2 : $this->nextPort($private + 1);
    }

    /** The machine the plan is made for. */
    private function environment(): Environment
    {
        return $this->context['environment'] ?? throw new \LogicException('The planner knows no environment');
    }

    private function nextPort(int $min): int
    {
        return $this->context['nextPort'] !== null
            ? $this->context['nextPort']($min)
            : (new Ports($this->environment()))->next($min);
    }

    /** Something made at random, once. */
    private function secret(string $key, int $length, bool $letters): string
    {
        return $this->secrets[$key] ??=
            $this->context['random'] !== null
                ? $this->context['random']($length, $letters)
                : self::randomString(
                    $length,
                    $letters
                        ? 'abcdefghijklmnopqrstuvwxyz'
                        : 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
                );
    }

    private static function randomString(int $length, string $alphabet): string
    {
        $string = '';
        for ($i = 0; $i < $length; $i++) {
            $string .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $string;
    }

    /** An answer among the choices there are, else the default. */
    private static function choice(mixed $value, array $choices, string $default): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, $choices, true) ? $value : $default;
    }
}
