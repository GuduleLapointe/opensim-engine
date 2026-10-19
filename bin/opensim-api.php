<?php

/**
 * opensim-api: the API of the engine on the command line (run by the script opensim-api), the same addresses and answers as the web (see the contract, docs/api/).
 *
 *   opensim-api [GET|POST|PATCH|DELETE] PATH [key=value...] [--data=JSON|@FILE|-] [--grid=NICK]
 *
 *   opensim-api GET /forms/grid
 *   opensim-api --grid=demo GET /monitor format=text
 *
 * The install is the one of opensim.conf (or the OPENSIM_* variables). The status is 0 for a success, 1 for a problem, 2 for a command
 * that is not understood.
 */

declare(strict_types=1);

use OpenSim\Api\Api;
use OpenSim\Api\Cli;
use OpenSim\Api\Router;
use OpenSim\Config\Layout;
use OpenSim\Runtime\Environment;
use OpenSim\Scheduling\Scheduler;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}
if (!class_exists(Router::class)) {
    fwrite(STDERR, "opensim-api: the autoload of the engine is not found (composer install)\n");
    exit(2);
}

$args = array_slice($_SERVER['argv'], 1);
$grid = null;
foreach ($args as $index => $arg) {
    if (str_starts_with($arg, '--grid=')) {
        $grid = substr($arg, 7);
        unset($args[$index]);
    }
}

try {
    $environment = Environment::system(Layout::resolve());
} catch (\RuntimeException $e) {
    fwrite(STDERR, 'opensim-api: ' . $e->getMessage() . "\n");
    exit(2);
}
$router = new Router(new Api($environment, Scheduler::for($environment)), grid: $grid);
[$status, $output, $error] = (new Cli($router))->run(
    array_values($args),
    static fn(): string => (string) stream_get_contents(STDIN),
);
fwrite(STDOUT, $output);
fwrite(STDERR, $error);
$router->terminate();

exit($status);
