<?php

declare(strict_types=1);

namespace OpenSim\Testing;

use OpenSim\Config\Layout;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\RuntimeProbe;
use OpenSim\Config\GridConfig;

/**
 * A machine that is a folder: an install with a core, a grid `demo` (its Robust, two simulators `alpha` and `beta`), the
 * instances enabled, and the fakes that stand for the processes, the consoles and the time.
 */
final class Machine
{
    public string $root;
    public string $etc;
    public string $core;
    public string $grid;
    public Environment $environment;
    public ProcessTableFake $processes;
    public LauncherFake $launcher;
    public ConsoleFactoryFake $consoles;
    public ClockFake $clock;
    public ReporterFake $reporter;

    /** @var array<string,string> what the fake HTTP answers, by address */
    public array $http = [];

    public function __construct()
    {
        $this->root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/machine-' . bin2hex(random_bytes(4));
        $this->etc = $this->root . '/etc';
        $this->core = $this->root . '/core/0.9.3.0';
        $this->grid = $this->etc . '/grids/demo';
        mkdir($this->core . '/bin', 0o755, true);
        foreach (['OpenSim.dll', 'Robust.dll'] as $file) {
            touch($this->core . "/bin/$file");
        }
        foreach (['OpenSim', 'Robust'] as $base) {
            file_put_contents(
                $this->core . "/bin/$base.exe.config",
                "<configuration><appender name=\"LogFileAppender\" type=\"x\"><file value=\"$base.log\" /></appender></configuration>",
            );
        }
        mkdir($this->root . '/logs');
        mkdir($this->root . '/var');
        mkdir($this->root . '/data');
        mkdir($this->etc . '/robust.d', 0o755, true);
        mkdir($this->etc . '/opensim.d');

        $this->write($this->grid . '/demo.conf', "[Grid]\nGridName = Demo\nCoreDirectory = {$this->core}\n");
        $this->write(
            $this->grid . '/Robust.HG.ini',
            <<<INI
            [Const]
                BinDirectory = "{$this->core}/bin"
                PublicPort = 8002
                PrivatePort = 8003
            [Startup]
                ServiceConnectors = ""
            [ServiceList]
                AssetServiceConnector = "8003/OpenSim.Server.Handlers.dll:AssetServiceConnector"
                GridServiceConnector = "8003/OpenSim.Server.Handlers.dll:GridServiceConnector"
            [Network]
                ConsolePort = 8404
                ConsoleUser = u
                ConsolePass = p
            INI
            ,
        );
        $simulators = GridConfig::simulatorsDirectory($this->grid);
        foreach (['alpha' => 9000, 'beta' => 9010] as $slug => $port) {
            $this->write(
                "$simulators/$slug.ini",
                <<<INI
                [Const]
                    LogsDirectory = "{$this->root}/logs"
                    RegistryLocation = "{$this->root}/data/$slug"
                [Startup]
                    regionload_regionsdir = "{$simulators}/$slug/regions"
                [Network]
                    http_listener_port = $port
                    ConsoleUser = u
                    ConsolePass = p
                    console_port = {$this->consolePort($port)}
                INI
                ,
            );
            $this->write(
                "$simulators/$slug/regions/Plaza.ini",
                "[Plaza]\nRegionUUID = 11111111-1111-4111-8111-11111111111" .
                    ($slug === 'alpha' ? '1' : '2') .
                    "\nInternalPort = " .
                    ($port + 1) .
                    "\n",
            );
        }
        symlink($this->grid . '/Robust.HG.ini', $this->etc . '/robust.d/demo.ini');
        symlink("$simulators/alpha.ini", $this->etc . '/opensim.d/alpha.ini');
        symlink("$simulators/beta.ini", $this->etc . '/opensim.d/beta.ini');

        $this->processes = new ProcessTableFake();
        $this->launcher = new LauncherFake($this->processes);
        $this->consoles = new ConsoleFactoryFake();
        $this->clock = new ClockFake();
        $this->reporter = new ReporterFake();
        $this->environment = new Environment(
            new Layout([
                'EtcRoot' => $this->etc,
                'CoreRoot' => $this->root . '/core',
                'VarRoot' => $this->root . '/var',
                'DataRoot' => $this->root . '/data',
                'LogsRoot' => $this->root . '/logs',
                'CacheRoot' => $this->root . '/cache',
            ]),
            $this->processes,
            $this->launcher,
            $this->consoles,
            $this->clock,
            $this->reporter,
            null,
            new RuntimeProbe(
                static fn(string $command): string => match (true) {
                    str_contains($command, 'command -v') => '/usr/bin/dotnet',
                    str_contains($command, '--list-runtimes') => "Microsoft.NETCore.App 8.0.4 [/usr/share/dotnet]\n",
                    str_contains($command, 'ldconfig') => "libgdiplus.so (libc6,x86-64) => /usr/lib/libgdiplus.so\n",
                    default => '',
                },
            ),
            fn(string $url): ?string => $this->http[$url] ?? null,
        );
    }

    /** The console port in the block of a simulator. */
    private function consolePort(int $port): int
    {
        return $port + 4;
    }

    public function write(string $path, string $text): string
    {
        is_dir(dirname($path)) || mkdir(dirname($path), 0o755, true);
        file_put_contents($path, $text);

        return $path;
    }

    /** What the instance named writes in its log when it starts, in the order. */
    public function startLog(string $name, string ...$lines): void
    {
        $this->launcher->behavior = function () use ($name, $lines): void {
            file_put_contents($this->root . "/logs/$name.log", implode("\n", $lines) . "\n", FILE_APPEND);
        };
    }
}
