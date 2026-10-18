<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Console\ScreenInterface;
use OpenSim\Controllers\Controller;
use OpenSim\Controllers\OpenSimController;

/**
 * Starts an instance: checks that it can run, maps its ports, links the modules, launches it detached, then waits until it
 * is ready (the services of a Robust loaded, the regions of a simulator up) or has failed, and says which.
 */
final class StartService
{
    /** Seconds an instance has to come up. */
    private const TIMEOUT = 120;

    /** The questions of a first start, which a region fully described does not ask: each is answered with its default. */
    private const QUESTIONS = ['RegionUUID', 'Internal IP address', 'External host name', 'Do you wish to join region .* to an existing estate', 'Name of estate to join'];

    public function __construct(private readonly Environment $environment)
    {
    }

    public function start(Controller $instance): Result
    {
        $env = $this->environment;
        $name = $instance->name();
        $core = $instance->core();

        // Only starting needs a core: stop and status work without one
        if ($core === null || !$core->exists()) {
            return $this->fail($name, 'could not find OpenSim.exe');
        }
        if (!is_file($instance->ini())) {
            return $this->fail($name, 'no ini file found for ' . $name);
        }

        // Dead sessions are dropped so that they are not mistaken for a running one
        $env->consoles->wipeSessions();
        if ($instance->isRunning()) {
            $env->reporter->debug("$name is already running");

            return new Result($name, Outcome::Running);
        }
        $env->reporter->info("starting $name");

        $base = $instance->executableBase();
        $runtime = $core->runtime($base);
        $executable = $core->executable($base);
        if (!is_dir($core->bin())) {
            return $this->fail($name, "$name Cannot cd to {$core->bin()}");
        }
        if (!is_file($core->bin() . "/$executable")) {
            return $this->fail($name, "Executable $executable not found");
        }
        // Fail early with a clear message if the runtime is missing, rather than letting the instance crash on launch
        if (($problem = $env->probe->problem($core, $executable)) !== null) {
            return $this->fail($name, $problem);
        }
        // A region stops at once without libgdiplus (dynamic textures)
        if ($base === 'OpenSim' && $runtime === 'dotnet' && PHP_OS_FAMILY === 'Linux' && !$env->probe->hasLibrary('gdiplus')) {
            return $this->fail($name, "$name cannot start: the native library libgdiplus is missing. Install it with: sudo apt install libgdiplus (or sudo opensim install-dotnet)");
        }
        // A simulator needs a region: without one it waits for the answers of a first start, on a console nobody reads
        if ($instance instanceof OpenSimController && $base === 'OpenSim' && !$instance->hasRegion()) {
            return $this->fail($name, "$name has no region to load (all disabled, or none yet): add one first, with opensim setup");
        }

        // The master of its grid, when it has one, is made again if the core or the grid changed: it is the first the config reads
        $master = $instance instanceof OpenSimController ? $instance->master($core->bin()) : null;

        // Port mapping, when the grid asks for it: ports mapped and regions address set before the instance starts
        $env->portMapping?->start($name, $instance->ini());

        // Regions load the modules enabled for the install from the addins folder of their registry
        $registry = $instance->launchSetting('RegistryLocation');
        if ($base === 'OpenSim' && $registry !== '') {
            foreach (ModuleLinker::sync("$registry/addins", $core, $env->layout->enabledModules(), $env->layout->modulesRoot()) as $note) {
                $env->reporter->debug($note);
            }
        }

        $logConfig = $this->logConfig($instance, $base, $core->bin());
        if ($logConfig === false) {
            return $this->fail($name, "could not make the log config of $name from {$core->bin()}/$base.exe.config");
        }
        $logPath = $instance->logFile()->path();
        if ($logConfig !== '') {
            // The log file is the one of the log config
            $logPath = LogConfig::logFile($logConfig) ?? $logPath;
            if (is_dir(dirname($logPath)) && !is_file($logPath)) {
                touch($logPath);
            }
        }
        $log = new LogFile($logPath);
        // Where this start begins in the log, before the instance writes to it
        $mark = $log->mark();

        $remote = $instance->remoteConsole();
        $native = $runtime === 'dotnet' ? $core->nativeLibraries($this->nativeRoot()) : '';
        $command = [$runtime, ...array_values(array_filter(explode(' ', $core->runtimeOptions($base)))), $core->bin() . "/$executable", '-inifile=' . $instance->ini()];
        if ($master !== null) {
            $command[] = '-inimaster=' . $master;
        }
        if ($base === 'Robust') {
            $prompt = $instance->launchSetting('consoleprompt', 'ConsolePrompt') ?: "Robust ($name) ";
            array_push($command, "-logfile=$logPath", '-prompt', $prompt);
        }
        if ($logConfig !== '') {
            $command[] = "-logconfig=$logConfig";
        }
        if ($remote !== null) {
            array_push($command, '-console', 'rest');
        }
        $env->reporter->debug(implode(' ', $command));

        $output = (string) tempnam(sys_get_temp_dir(), 'opensim-start-');
        $spec = new LaunchSpec(
            $name,
            $core->bin(),
            $command,
            $native !== '' ? ['LD_LIBRARY_PATH' => $native . ((string) getenv('LD_LIBRARY_PATH') !== '' ? ':' . getenv('LD_LIBRARY_PATH') : '')] : [],
            $output,
            $remote === null,
            $base === 'OpenSim',
        );
        try {
            if (!$env->launcher->launch($spec)) {
                return $this->fail($name, "$name failed to start: the launcher could not run it");
            }
            // Confirm it came up: an immediate exit (a missing or incompatible runtime) would leave us waiting for nothing
            $env->clock->sleep(1);
            if (!$instance->isRunning()) {
                $this->tell($output, 0);

                return $this->fail($name, "$name failed to start: the process exited immediately");
            }

            return $this->wait($instance, $base, $log, $mark, $output, $remote !== null);
        } finally {
            is_file($output) && unlink($output);
        }
    }

    /** Wait for the instance to be ready, and tell how it went. */
    private function wait(Controller $instance, string $base, LogFile $log, int $mark, string $output, bool $remote): Result
    {
        $env = $this->environment;
        $name = $instance->name();
        $failed = [];

        if ($base === 'Robust') {
            // Every service its config lists must report it loaded, in the log
            $services = RobustServices::of($instance);
            if ($services === []) {
                $env->reporter->debug("no service listed in {$instance->ini()}, only checking the instance stays up");
                $env->clock->sleep(3);
                $code = $instance->isRunning() ? 0 : 3;
            } else {
                [$code, $failed] = $this->watchServices($instance, $log, $mark, $services);
            }
        } else {
            $progress = new StartProgress($env, $instance, $log, $mark, $output);
            $code = $remote
                ? $this->waitLog($instance, $log, $mark, $progress)
                : $this->waitConsole($instance, $progress);
            // The regions are loaded before the physics and the scripts start: a region that cannot run them ends within seconds
            if ($code === 0) {
                $env->clock->sleep(5);
                $code = $instance->isRunning() ? 0 : 3;
            }
            $progress->finish();
        }
        $env->reporter->stepEnd();

        switch ($code) {
            case 0:
                $env->reporter->info("$name started");

                return new Result($name, Outcome::Started);
            case 4:
                return $this->fail($name, "$name is up but these services failed to load: " . implode(' ', $failed), "Its log: {$log->path()}");
            case 3:
                $this->tell($output, 20);

                return $this->fail($name, "$name exited while starting");
            default:
                $env->reporter->info("$name still starting, let it run and check status later");

                return new Result($name, Outcome::Starting);
        }
    }

    /**
     * Follow the log of a Robust while it loads its connectors, showing each one as it is reported. The log, not the console: a
     * detached screen cuts the lines at 80 columns.
     *
     * @param list<string> $services the class names
     * @return array{0:int,1:list<string>} 0 when all are loaded, 4 when some failed to load (named in the list, once the others
     *                                     have reported), 3 when the instance exited, 1 when the delay is over
     */
    private function watchServices(Controller $instance, LogFile $log, int $mark, array $services): array
    {
        $env = $this->environment;
        $pending = array_fill_keys($services, true);
        $total = count($services);
        $reported = 0;
        $failed = [];
        for ($tick = 0; $tick < self::TIMEOUT * 2; $tick++) {
            $content = $log->since($mark);
            foreach (array_keys($pending) as $class) {
                if (preg_match('/\[SERVER\]: ' . preg_quote($class, '/') . ' loaded successfully/', $content)) {
                    $env->reporter->step("  $class " . ++$reported . "/$total");
                    unset($pending[$class]);
                } elseif (preg_match('/Failed to load .*:' . preg_quote($class, '/') . '([^A-Za-z0-9]|$)/', $content)) {
                    $env->reporter->stepEnd();
                    $env->reporter->error("  $class: failed to load (" . ++$reported . "/$total)");
                    $failed[] = $class;
                    unset($pending[$class]);
                }
            }
            if ($pending === []) {
                return [$failed === [] ? 0 : 4, $failed];
            }
            if (!$instance->isRunning()) {
                return [3, $failed];
            }
            $env->clock->sleep(0.5);
        }

        return [1, $failed];
    }

    /** Wait for the regions of a simulator with a remote console to be ready, in its log: 0, 3 when it exited, 1 at the end of the delay. */
    private function waitLog(Controller $instance, LogFile $log, int $mark, StartProgress $progress): int
    {
        for ($tick = 0; $tick < self::TIMEOUT * 2; $tick++) {
            if (preg_match('/RegionReady|LOAD REGIONS PLUGIN\]: Done/', $log->since($mark))) {
                return 0;
            }
            $progress->show();
            if (!$instance->isRunning()) {
                return 3;
            }
            $this->environment->clock->sleep(0.5);
        }

        return 1;
    }

    /**
     * Wait for the regions of a simulator in a screen session. The questions of a first start (region, estate) are answered with
     * their default, each once, as they come: a region fully described asks none. A question blocks the loading, so the region is
     * ready only after them.
     */
    private function waitConsole(Controller $instance, StartProgress $progress): int
    {
        $console = $this->environment->consoles->screen($instance->name());
        $answered = [];
        while (true) {
            $pattern = 'RegionReady|neighbours.*region.*is up|LOAD REGIONS PLUGIN\]: Done';
            foreach (self::QUESTIONS as $i => $question) {
                $pattern .= isset($answered[$i]) ? '' : "|$question";
            }
            [$code, $seen] = $this->waitScreen($instance, $console, $pattern, $progress);
            if ($code !== 0) {
                return $code;
            }
            $asked = false;
            foreach (self::QUESTIONS as $i => $question) {
                if (!isset($answered[$i]) && preg_match("/$question/", $seen)) {
                    $console->send('');
                    $answered[$i] = true;
                    $asked = true;

                    break;
                }
            }
            if (!$asked) {
                return 0;
            }
        }
    }

    /**
     * Wait for a pattern in the screen of an instance.
     *
     * @return array{0:int,1:string} 0 and the lines that match, 3 when the session is gone, 1 at the end of the delay
     */
    private function waitScreen(Controller $instance, ScreenInterface $console, string $pattern, StartProgress $progress): array
    {
        for ($tick = 0; $tick < self::TIMEOUT * 2; $tick++) {
            $matches = array_filter(explode("\n", $console->screenText()), static fn(string $line): bool => (bool) preg_match("/$pattern/", $line));
            if ($matches !== []) {
                return [0, implode("\n", $matches)];
            }
            $progress->show();
            // Stop waiting if the session is gone: the instance has exited
            if (!$console->exists()) {
                return [3, ''];
            }
            $this->environment->clock->sleep(0.5);
        }

        return [1, ''];
    }

    /**
     * The log config of an instance: the one it names, else the one made with the grid, else one made from the model of the core.
     *
     * @return string|false the file, empty when there is none to give, false when it could not be made
     */
    private function logConfig(Controller $instance, string $base, string $bin): string|false
    {
        $layout = $this->environment->layout;
        $config = $instance->launchSetting('logconfig', 'LogConfig');
        if ($config === '' && is_file($instance->launchSetting('EtcDirectory') . "/$base.exe.config")) {
            $config = $instance->launchSetting('EtcDirectory') . "/$base.exe.config";
        }
        if ($config === '') {
            $data = $instance->launchSetting('datadirectory') ?: $layout->dataRoot();
            $config = "$data/" . basename($instance->ini(), '.ini') . '.LogConfig';
        }
        if (!is_file($config)) {
            $model = "$bin/$base.exe.config";
            if (!is_file($model)) {
                return false;
            }
            $text = LogConfig::render($model, $base, $instance->logsDirectory(), $instance->name());
            if (!is_dir(dirname($config)) || file_put_contents($config, $text) === false) {
                return false;
            }
            $this->environment->reporter->debug("created LogConfig file $config from core model $model");
        }

        return $config;
    }

    /** Where the native libraries of the cores are linked. */
    private function nativeRoot(): string
    {
        return $this->environment->layout->stateRoot() . '/native';
    }

    /** Tell what the process printed: the whole output, or its last lines. */
    private function tell(string $output, int $last): void
    {
        $text = is_file($output) ? trim((string) file_get_contents($output)) : '';
        if ($text === '') {
            return;
        }
        $lines = explode("\n", $text);
        $this->environment->reporter->error(implode("\n", $last > 0 ? array_slice($lines, -$last) : $lines));
    }

    private function fail(string $name, string $message, string $debug = ''): Result
    {
        $this->environment->reporter->stepEnd();
        $this->environment->reporter->error($message);
        $debug !== '' && $this->environment->reporter->debug($debug);

        return Result::failed($name, $message);
    }
}
