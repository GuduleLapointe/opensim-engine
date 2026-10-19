<?php

declare(strict_types=1);

namespace OpenSim\Controllers;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\ConnectionString;
use OpenSim\Config\GridFileService;
use OpenSim\Config\InstanceSettings;
use OpenSim\Console\ConsoleInterface;
use OpenSim\Data\Database;
use OpenSim\Interfaces\Enablable;
use OpenSim\Models\Grid;
use OpenSim\Runtime\Core;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\LogFile;
use OpenSim\Runtime\Process;
use OpenSim\Runtime\InstanceResult;
use OpenSim\Runtime\StartService;
use OpenSim\Runtime\StopService;
use OpenSim\Config\GridConfig;

/**
 * What runs an OpenSimulator program, launched with dotnet from a config file: a Robust (RobustController), an OpenSim that runs a
 * simulator (OpenSimController), later the money server. It is what is started, stopped and talked to, and what reads the config it
 * runs with. It provides services, it does not own them: they are the grid's (see Service).
 *
 * The config file is the one the controller runs with (`-inifile=`): the link in `opensim.d` or `robust.d` of an enabled
 * controller, which is where the launcher found it, else the file itself.
 */
abstract class AbstractController implements Enablable
{
    /** The sections the launcher reads its settings in, the last that says one wins. */
    private const LAUNCH_SECTIONS = ['Const', 'Launch', 'Startup', 'Network'];

    /** @var ?array<string,array<string,string>> */
    private ?array $settings = null;

    /** @var ?array<string,mixed> what its own config says (see InstanceSettings) */
    private ?array $own = null;

    private readonly string $name;

    public function __construct(
        protected readonly Environment $environment,
        private readonly string $ini,
        ?string $name = null,
    ) {
        $this->name = self::cleanName($name ?? basename($ini, '.ini'));
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    /** `robust` or `opensim`. */
    abstract public function type(): string;

    /** The base name of the assembly it runs: Robust, OpenSim. */
    abstract public function assembly(): string;

    /** The base name of the assembly to run: the one its config names (`Executable`), else the one of its kind. */
    public function executableBase(): string
    {
        $executable = $this->launchSetting('executable', 'Executable');

        return $executable !== ''
            ? (string) preg_replace('/\.(exe|dll)$/', '', basename($executable))
            : $this->assembly();
    }

    /** The name an instance has: lower case, letters, digits, underscores and hyphens (the name of its screen session). */
    public static function cleanName(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9_-]+/', '', strtolower($name));
    }

    public function name(): string
    {
        return $this->name;
    }

    /** Whether its config can be linked where the launcher finds it: it can be enabled and disabled. */
    public function canBeEnabled(): bool
    {
        $folder = dirname($this->link());

        return is_writable(is_dir($folder) ? $folder : dirname($folder));
    }

    /** The config file the instance runs with. */
    public function ini(): string
    {
        return $this->ini;
    }

    /**
     * The settings of its config, the files it includes read the way OpenSimulator reads them.
     *
     * @return array<string,array<string,string>> section => key => value
     */
    public function settings(): array
    {
        return $this->settings ??= ConfigReader::load($this->configFiles());
    }

    /** Read its config again the next time. */
    protected function forget(): void
    {
        $this->settings = null;
        $this->own = null;
    }

    /** Whether its config is there. */
    public function exists(): bool
    {
        return is_file($this->ini);
    }

    /** What its own file says, in the names of InstanceSettings; nothing for an instance that is not made yet. */
    protected function own(string $key): mixed
    {
        return ($this->own ??= $this->exists() ? InstanceSettings::of($this->ini) : [])[$key] ?? null;
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

    /**
     * A database it connects to, as OpenSimulator chooses it from its config: a Database, or the section of a service of Robust
     * (UserAccountService, GridService...). The setting of the service when it has one, else [DatabaseService].
     */
    public function connection(Database|string $which): ?ConnectionString
    {
        return $which instanceof Database
            ? $which->connectionOf($this->settings(), $this instanceof Robust)
            : Database::serviceOf($this->settings(), $which);
    }

    /** The database of what it keeps: the services of a Robust, the regions of a simulator. */
    abstract public function database(): ?ConnectionString;

    /** The data source of that database (host and port as written), null when the config does not say it. */
    public function databaseHost(): ?string
    {
        return $this->database()?->source;
    }

    public function databaseName(): ?string
    {
        return $this->database()?->database;
    }

    public function databaseUser(): ?string
    {
        return $this->database()?->user;
    }

    public function databasePassword(): ?string
    {
        return $this->database()?->password;
    }

    /**
     * The files its config is made of, in the order OpenSimulator reads them: the master of its grid when it has one, then its own.
     *
     * @return list<string>
     */
    protected function configFiles(): array
    {
        return [$this->ini];
    }

    /** One setting of a section, case of the key as written; empty when the config does not say it. */
    public function setting(string $section, string $key): string
    {
        return trim((string) ($this->settings()[$section][$key] ?? ''));
    }

    /**
     * A setting of the launcher, by the first of these names the config says (the case matters, as in the ini): [Const],
     * [Launch], [Startup] and [Network] are looked in, the last that has it wins.
     */
    public function launchSetting(string ...$names): string
    {
        foreach ($names as $name) {
            $found = '';
            foreach (self::LAUNCH_SECTIONS as $section) {
                $value = trim((string) ($this->settings()[$section][$name] ?? ''));
                $found = $value !== '' ? $value : $found;
            }
            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }

    /** The folder of its grid (`<etc>/grids/<nick>`), null for a config that is not in one. */
    public function gridDirectory(): ?string
    {
        $real = realpath($this->ini);
        if ($real === false) {
            return null;
        }
        $dir = dirname($real);
        $dir = basename($dir) === GridConfig::SIMULATORS ? dirname($dir) : $dir;

        return basename(dirname($dir)) === 'grids' ? $dir : null;
    }

    /** The grid it belongs to, null for a config that is not in the folder of one. */
    public function grid(): ?Grid
    {
        $dir = $this->gridDirectory();

        return $dir === null ? null : Grid::at($this->environment, $dir);
    }

    /**
     * The core it runs from: the one it names (BinDirectory), else the one chosen for its grid (`CoreDirectory` in
     * `<nick>.conf`), else the one of the install.
     */
    public function core(): ?Core
    {
        // The core it was made for: its own config says it, else the grid does (the master of the grid follows the grid)
        $own = ConfigReader::load($this->ini);
        $bin = '';
        foreach (['BinDirectory', 'BinDir'] as $name) {
            foreach (self::LAUNCH_SECTIONS as $section) {
                $bin = trim((string) ($own[$section][$name] ?? '')) ?: $bin;
            }
            if ($bin !== '') {
                break;
            }
        }
        if ($bin !== '') {
            return Core::ofBin($bin);
        }
        if (($grid = $this->gridDirectory()) !== null) {
            $conf = ConfigReader::load("$grid/" . basename($grid) . '.conf')['Grid'] ?? [];
            if (trim((string) ($conf['CoreDirectory'] ?? '')) !== '') {
                return Core::at(trim($conf['CoreDirectory']));
            }
        }

        return $this->environment->layout->core();
    }

    /**
     * Its remote console: the port, a user and a password make one. Null when it has none (it runs in a screen session).
     *
     * @return array{port:int,user:string,password:string}|null
     */
    public function remoteConsole(): ?array
    {
        $port = $this->launchSetting(...$this->consolePortKeys());
        $user = $this->launchSetting('ConsoleUser', 'consoleuser');
        $password = $this->launchSetting('ConsolePass', 'consolepass');
        if (!ctype_digit($port) || (int) $port < 1 || $user === '' || $password === '') {
            return null;
        }

        return ['port' => (int) $port, 'user' => $user, 'password' => $password];
    }

    /** @return list<string> the keys of the port of its console, by order of preference */
    abstract protected function consolePortKeys(): array;

    /** The folder of `etc` where the enabled instances of its kind are linked: `robust.d`, `opensim.d`. */
    abstract public static function enabledFolder(): string;

    /** The link that enables it: a config linked in the enabled folder, where the launcher finds it. */
    public function link(): string
    {
        return rtrim($this->environment->layout->etcRoot(), '/') .
            '/' .
            static::enabledFolder() .
            '/' .
            $this->linkName() .
            '.ini';
    }

    /** The name of its link: the name of its config file, or of its grid for a Robust. */
    protected function linkName(): string
    {
        return basename($this->ini, '.ini');
    }

    public function isEnabled(): bool
    {
        return is_link($this->link()) || is_file($this->link());
    }

    /** Link its config where the launcher finds it, so that it starts with the others. */
    public function enable(): bool
    {
        $target = realpath($this->ini) ?: $this->ini;
        if (!is_file($target) || $target === $this->link()) {
            return false;
        }
        $link = $this->link();
        if (!is_dir(dirname($link)) && !mkdir(dirname($link), 0o755, true) && !is_dir(dirname($link))) {
            return false;
        }
        if (is_link($link) || is_file($link)) {
            unlink($link);
        }

        return symlink($target, $link);
    }

    /** Stop it from starting with the others. A running instance is not stopped. */
    public function disable(): void
    {
        if (is_link($this->link()) || is_file($this->link())) {
            unlink($this->link());
        }
    }

    public function console(): ConsoleInterface
    {
        return $this->environment->consoles->for($this);
    }

    /**
     * The ports it listens on, with what each one is for and who has to reach it:
     *
     *  - public:  the viewers, the other grids, the other simulators
     *  - private: the simulators of the grid only (Robust private port, without any authentication: never for the world)
     *  - console: the REST console, which gives every right on the instance
     *  - off:     kept for a console that is not enabled
     *
     * The region ports are UDP: they are the ones the viewers send to, and the number is the one the viewers get, so it cannot
     * differ outside.
     *
     * @return list<array{port:int,proto:string,role:string,use:string}>
     */
    public function ports(): array
    {
        $uses = $this->listeningPorts();
        if (($console = $this->remoteConsole()) !== null) {
            self::addPort($uses, $console['port'], 'tcp', 'REST console (every right on the instance)', 'console');
        } elseif ($this->launchSetting(...$this->consolePortKeys()) === '') {
            self::addPort($uses, $this->keptConsolePort(), 'tcp', 'REST console (not enabled)', 'off');
        }
        usort($uses, static fn(array $a, array $b): int => [$a['port'], $a['proto']] <=> [$b['port'], $b['proto']]);

        return $uses;
    }

    /**
     * The ports its kind of program listens on, besides its console.
     *
     * @return list<array{port:int,proto:string,role:string,use:string}>
     */
    abstract protected function listeningPorts(): array;

    /**
     * Adds a use of a port to a list, unless the port is not a number above zero.
     *
     * @param list<array{port:int,proto:string,role:string,use:string}> $uses
     */
    protected static function addPort(array &$uses, int|string|null $port, string $proto, string $role, string $use): void
    {
        if ($port !== null && ctype_digit((string) $port) && (int) $port > 0) {
            $uses[] = ['port' => (int) $port, 'proto' => $proto, 'role' => $role, 'use' => $use];
        }
    }

    /** The port of its console as the config keeps it for when it is enabled (the line is commented out), null when it does not. */
    private function keptConsolePort(): ?int
    {
        $text = is_file($this->ini) ? GridFileService::read($this->ini) : '';
        foreach ($this->consolePortKeys() as $key) {
            if (preg_match('/^\s*;+\s*' . preg_quote($key, '/') . '\s*=\s*"?(\d+)/mi', $text, $m) === 1 && (int) $m[1] > 0) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /** One setting of a section, the names of the section and of the key in any case; empty when the config does not say it. */
    protected function settingAnyCase(string $section, string $key): string
    {
        foreach ($this->settings() as $name => $keys) {
            if (strcasecmp((string) $name, $section) === 0) {
                foreach ($keys as $other => $value) {
                    if (strcasecmp((string) $other, $key) === 0) {
                        return trim((string) $value);
                    }
                }
            }
        }

        return '';
    }

    /** The folder its logs are in: the one it names, else the one of its includes, else the one of the install. */
    public function logsDirectory(): string
    {
        $common = str_replace(
            ['${Launch|SimName}', '${Launch|MachineName}'],
            $this->name,
            $this->setting('Const', 'LogsRoot'),
        );

        return $this->launchSetting('LogsDirectory') ?: ($common ?: $this->environment->layout->logsRoot());
    }

    /** The log file of the instance, as far as its config tells (its log config may say another). */
    public function logFile(): LogFile
    {
        return new LogFile($this->launchSetting('LogFile') ?: $this->logsDirectory() . '/' . $this->name . '.log');
    }

    /** The process of the instance, null when it does not run. */
    public function process(): ?Process
    {
        $real = realpath($this->ini) ?: $this->ini;
        foreach ($this->environment->processes->all() as $process) {
            if ($process->runs($this->ini) || $process->runs($real)) {
                return $process;
            }
        }

        return null;
    }

    /** Whether it runs: its process, or its screen session (which comes first). */
    public function isRunning(): bool
    {
        return $this->environment->consoles->sessionExists($this->name) || $this->process() !== null;
    }

    public function start(): InstanceResult
    {
        return (new StartService($this->environment))->start($this);
    }

    /** @param bool $now stop without warning the users */
    public function stop(bool $now = false): InstanceResult
    {
        return (new StopService($this->environment))->stop($this, $now);
    }

    /** @param bool $now stop without warning the users */
    public function restart(bool $now = false): InstanceResult
    {
        $this->stop($now);

        return $this->start();
    }
}
