<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\ConfigReader;
use OpenSim\Provisioning\LocationFinder;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\Result;

/**
 * A grid: the folder `<etc>/grids/<nick>` that holds its Robust config, its description (`<nick>.conf`) and the configs of
 * its simulators (`sims/<name>.ini`). A grid is run from this machine (its Robust, its simulators) or only described here, its
 * Robust being elsewhere.
 *
 * The services of a grid (its Robust) start, stop and restart on their own: its simulators do not need to restart with them.
 * They are included when asked, and then stop first and start last.
 */
final class Grid
{
    /** The files that hold the Robust config, the first that exists is the one. */
    private const ROBUST_FILES = ['Robust.HG.ini', 'Robust.ini'];

    private function __construct(
        private readonly Environment $environment,
        private readonly string $directory,
    ) {
    }

    /**
     * The fields of a grid, in the standard format of the forms (see Forms\FieldSet): what the setup asks to make or to change one,
     * and what any other form about a grid (administration) shows. The groups are the screens of the setup.
     *
     * @param array{cores?:array<string,string>,modify?:bool} $options the cores to run it (folder => name; with one there is no
     *        question) and whether the grid exists (its nick is not changed)
     * @return list<array<string,mixed>>
     */
    public static function fields(array $options = []): array
    {
        $cores = $options['cores'] ?? [];

        return [
            ['name' => 'name', 'group' => 'grid', 'type' => 'string', 'required' => true, 'label' => _('Grid name')],
            [
                // The nick names the folder, the database and the instances: it is not changed afterwards
                'name' => 'nick',
                'follows' => ['name'],
                'group' => 'grid',
                'type' => 'string',
                'required' => true,
                'label' => _('Grid nick (snake_case)'),
                'description' => _('Names its folder, its database and its instances'),
                'enable' => !($options['modify'] ?? false),
            ],
            ['name' => 'hypergrid', 'group' => 'grid', 'type' => 'boolean', 'label' => _('Enable Hypergrid?')],
            [
                'name' => 'spacing',
                'group' => 'grid',
                'type' => 'integer',
                'required' => true,
                'label' => _('Free blocks between regions (0: side by side)'),
                'callback' => [self::class, 'validateSpacing'],
            ],
            [
                'name' => 'core',
                'group' => 'grid',
                'type' => 'select',
                'label' => _('OpenSim core to run this grid'),
                'options' => $cores,
                'enable' => count($cores) > 1,
            ],
            ['name' => 'hostname', 'group' => 'network', 'type' => 'string', 'required' => true, 'label' => _('Base hostname')],
            [
                'name' => 'public_port',
                'group' => 'network',
                'type' => 'integer',
                'required' => true,
                'label' => _('Public port'),
                'callback' => [self::class, 'validatePort'],
            ],
            [
                'name' => 'private_port',
                'follows' => ['public_port'],
                'group' => 'network',
                'type' => 'integer',
                'required' => true,
                'label' => _('Private port'),
                'callback' => [self::class, 'validatePort'],
            ],
            [
                'name' => 'center',
                'follows' => ['public_port'],
                'group' => 'network',
                'type' => 'string',
                'required' => true,
                'label' => _('Center of the grid (x,y)'),
                'description' => _('Where new regions are searched from, kept'),
                'callback' => [self::class, 'validateCenter'],
            ],
            [
                'name' => 'console',
                'group' => 'network',
                'type' => 'select',
                'label' => _('Console of the grid'),
                'options' => ['rest' => _('Remote REST console (recommended)'), 'screen' => _('Screen session (on this machine)')],
            ],
            [
                'name' => 'console_port',
                'follows' => ['public_port', 'private_port'],
                'group' => 'network',
                'type' => 'integer',
                'required' => true,
                'label' => _('Console port'),
                'callback' => [self::class, 'validatePort'],
                'condition' => ['console' => 'rest'],
            ],
            ['name' => 'web_url', 'follows' => ['hostname'], 'group' => 'network', 'type' => 'string', 'required' => true, 'label' => _('Web URL')],
            [
                'name' => 'helpers',
                'group' => 'network',
                'type' => 'boolean',
                'label' => _('Serve the economy and the search of the grid with opensim-helpers?'),
            ],
            [
                'name' => 'helpers_path',
                'group' => 'network',
                'type' => 'string',
                'required' => true,
                'label' => _('Helpers path'),
                'description' => _('After the Web URL: the viewers add the name of the script'),
                'callback' => [self::class, 'validateHelpersPath'],
                'condition' => ['helpers' => true],
            ],
            ...self::optionFields($options),
            ['name' => 'database_host', 'group' => 'database', 'type' => 'string', 'required' => true, 'label' => _('Database host')],
            ['name' => 'database_name', 'follows' => ['nick'], 'group' => 'database', 'type' => 'string', 'required' => true, 'label' => _('Database name')],
            ['name' => 'database_user', 'group' => 'database', 'type' => 'string', 'required' => true, 'label' => _('Database user')],
            ['name' => 'database_password', 'group' => 'database', 'type' => 'string', 'required' => true, 'label' => _('Database password')],
        ];
    }

    /** The shared search provider a grid can use instead of its own: the one of 2do.directory. */
    public const SHARED_SEARCH = 'https://2do.directory/helpers';

    /**
     * The options of a new grid, each with its default: what the setup offers to enable or not. They are decided when the grid is made;
     * after that `opensim enable|disable` and the files of the grid decide.
     *
     * @param array{modify?:bool} $options
     * @return list<array<string,mixed>>
     */
    private static function optionFields(array $options): array
    {
        $new = !($options['modify'] ?? false);

        return [
            [
                'name' => 'search',
                'group' => 'options',
                'type' => 'select',
                'label' => _('Search of the grid'),
                'options' => [
                    'internal' => _('Provided by this grid (opensim-helpers)'),
                    'shared' => _('Shared provider (2do.directory)'),
                    'disabled' => _('No search'),
                ],
                'callback' => static fn(mixed $value, array $answers): ?string => $value === 'internal' && !($answers['helpers'] ?? true)
                    ? _('The search of this grid is served by opensim-helpers.')
                    : null,
                'enable' => $new,
            ],
            [
                'name' => 'economy',
                'group' => 'options',
                'type' => 'select',
                'label' => _('Currency'),
                'options' => ['beta' => _('BetaGridLikeMoneyModule (no real money)'), 'gloebit' => _('Gloebit')],
                'enable' => $new,
            ],
            [
                'name' => 'ossl',
                'group' => 'options',
                'type' => 'boolean',
                'label' => _('Allow more OSSL functions to parcel owners (NPC, animations, drawing...)?'),
                'enable' => $new,
            ],
            [
                'name' => 'web_server',
                'group' => 'options',
                'type' => 'select',
                'label' => _('Web server'),
                'options' => ['caddy' => 'Caddy', 'apache' => 'Apache', 'nginx' => 'nginx'],
                'condition' => ['helpers' => true],
                'enable' => $new,
            ],
            [
                'name' => 'web_site',
                'group' => 'options',
                'type' => 'select',
                'label' => _('Web site'),
                'options' => ['helpers' => _('The helpers only'), 'website' => _('The helpers and the mini website (opensim-web)')],
                'condition' => ['helpers' => true],
                'enable' => $new,
            ],
        ];
    }

    /**
     * The fields to join a grid whose Robust runs on another machine (or in another container): what a simulator needs of it, which
     * is kept for the next ones. Its name and its nick are the grid's own when they are left out.
     *
     * @return list<array<string,mixed>>
     */
    public static function remoteFields(): array
    {
        return [
            ['name' => 'address', 'group' => 'remote', 'type' => 'string', 'required' => true, 'label' => _('Address of the grid (host:port)')],
            [
                'name' => 'name',
                'group' => 'remote',
                'type' => 'string',
                'required' => false,
                'label' => _('Grid name'),
                'description' => _('Empty: the grid tells it'),
            ],
            [
                'name' => 'nick',
                'group' => 'remote',
                'type' => 'string',
                'required' => false,
                'label' => _('Grid nick (snake_case)'),
                'description' => _('Empty: the grid tells it'),
            ],
            [
                'name' => 'private_port',
                'group' => 'remote',
                'type' => 'integer',
                'required' => false,
                'label' => _('Private port of the grid (only for its simulators, and this machine is one)'),
                'description' => _('Empty: the public port plus one'),
                'callback' => [self::class, 'validatePort'],
            ],
            ['name' => 'hypergrid', 'group' => 'remote', 'type' => 'boolean', 'label' => _('Does the grid use Hypergrid?')],
        ];
    }

    /** A port number. */
    public static function validatePort(mixed $value): ?string
    {
        return ctype_digit(trim((string) $value)) ? null : _('Enter a port number.');
    }

    /** A number of free blocks between regions. */
    public static function validateSpacing(mixed $value): ?string
    {
        return ctype_digit(trim((string) $value)) && (int) $value <= 50 ? null : _('A number of blocks, 0 to 50.');
    }

    /** A place of the map, x,y. */
    public static function validateCenter(mixed $value): ?string
    {
        return LocationFinder::parse((string) $value) === null ? _('Use x,y (e.g. 8002,8002).') : null;
    }

    /** The path of the helpers on the web site. */
    public static function validateHelpersPath(mixed $value): ?string
    {
        return preg_match('#^/?[A-Za-z0-9._/-]*$#', trim((string) $value)) === 1 ? null : _('A path such as /helpers.');
    }

    /** The grid whose folder is given. */
    public static function at(Environment $environment, string $directory): self
    {
        return new self($environment, rtrim($directory, '/'));
    }

    /** The grid of this nick on this machine, null when there is none. */
    public static function find(Environment $environment, string $nick): ?self
    {
        $directory = rtrim($environment->layout->etcRoot(), '/') . "/grids/$nick";

        return $nick !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $nick) && is_dir($directory) ? new self($environment, $directory) : null;
    }

    /**
     * The grids of this machine.
     *
     * @return list<self>
     */
    public static function all(Environment $environment): array
    {
        $grids = [];
        foreach (glob(rtrim($environment->layout->etcRoot(), '/') . '/grids/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $grids[] = new self($environment, $directory);
        }

        return $grids;
    }

    public function nick(): string
    {
        return basename($this->directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The settings of its description, `[Grid]` of `<nick>.conf`.
     *
     * @return array<string,string>
     */
    public function settings(): array
    {
        return ConfigReader::load($this->directory . '/' . $this->nick() . '.conf')['Grid'] ?? [];
    }

    /** Whether its Robust is on another machine: the grid is only described here. */
    public function isRemote(): bool
    {
        return $this->robustIni() === null && in_array(strtolower(trim($this->settings()['Remote'] ?? '')), ['true', '1', 'yes'], true);
    }

    /** The config file of its Robust, null for a grid that has none (a remote grid). */
    public function robustIni(): ?string
    {
        foreach (self::ROBUST_FILES as $file) {
            if (is_file("{$this->directory}/$file")) {
                return "{$this->directory}/$file";
            }
        }

        return null;
    }

    /** Its Robust on this machine, null for a remote grid. Run with the link that enables it when it is enabled. */
    public function robust(): ?Robust
    {
        $file = $this->robustIni();
        if ($file === null) {
            return null;
        }
        $robust = new Robust($this->environment, $file, $this->nick());

        return $robust->isEnabled() ? new Robust($this->environment, $robust->link(), $this->nick()) : $robust;
    }

    /**
     * Its simulators, enabled or not, by name.
     *
     * @return list<Simulator>
     */
    public function simulators(): array
    {
        $files = glob($this->directory . '/sims/*.ini') ?: [];
        sort($files);
        $simulators = [];
        foreach ($files as $file) {
            $simulator = new Simulator($this->environment, $file);
            $simulators[] = $simulator->isEnabled() ? new Simulator($this->environment, $simulator->link()) : $simulator;
        }

        return $simulators;
    }

    /** @return list<Simulator> the simulators that start with the others */
    public function enabledSimulators(): array
    {
        return array_values(array_filter($this->simulators(), static fn(Simulator $s): bool => $s->isEnabled()));
    }

    /** One of its simulators by name. */
    public function simulator(string $name): ?Simulator
    {
        foreach ($this->simulators() as $simulator) {
            if ($simulator->name() === Instance::cleanName($name)) {
                return $simulator;
            }
        }

        return null;
    }

    /** Whether the grid is run from this machine: its Robust is enabled. */
    public function isEnabled(): bool
    {
        return $this->robust()?->isEnabled() ?? false;
    }

    /** Enable its Robust. */
    public function enable(): bool
    {
        return $this->robust()?->enable() ?? false;
    }

    public function disable(): void
    {
        $this->robust()?->disable();
    }

    /**
     * The instances to run, in the order they start: its Robust, then, when asked, its enabled simulators.
     *
     * @return list<Instance>
     */
    public function instances(bool $simulators = false): array
    {
        $robust = $this->robust();

        return [...($robust !== null && $robust->isEnabled() ? [$robust] : []), ...($simulators ? $this->enabledSimulators() : [])];
    }

    /**
     * Start the services of the grid, then its simulators when asked. A simulator is not started when Robust did not come up.
     *
     * @return list<Result>
     */
    public function start(bool $simulators = false): array
    {
        $results = [];
        foreach ($this->instances($simulators) as $instance) {
            $result = $instance->start();
            $results[] = $result;
            if ($instance instanceof Robust && !$result->ok()) {
                break;
            }
        }

        return $results;
    }

    /**
     * Stop the services of the grid, its simulators first when asked.
     *
     * @return list<Result>
     */
    public function stop(bool $now = false, bool $simulators = false): array
    {
        return array_map(static fn(Instance $i): Result => $i->stop($now), array_reverse($this->instances($simulators)));
    }

    /**
     * Restart the services of the grid, and its simulators when asked: everything stops, then everything starts.
     *
     * @return list<Result>
     */
    public function restart(bool $now = false, bool $simulators = false): array
    {
        return [...$this->stop($now, $simulators), ...$this->start($simulators)];
    }
}
