<?php

/**
 * opensim-complete-grids: what a package that is installed adds to the grids it finds, without the setup: the files of the grid that
 * are missing (the MoneyServer.ini the helpers need, from the original of the MoneyServer, with its key made at random). What exists
 * is not touched: the setup does the merging.
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use OpenSim\Config\Layout;
use OpenSim\Provisioning\MoneyServerConfig;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}
if (!class_exists(MoneyServerConfig::class)) {
    fwrite(STDERR, "opensim-complete-grids: the autoload of the engine is not found\n");
    exit(2);
}

$etcRoot = Layout::resolve()->etcRoot();
foreach (glob("$etcRoot/grids/*", GLOB_ONLYDIR) ?: [] as $dir) {
    // A grid with helpers has a Helpers.ini, and its helpers need the key
    if (!is_file("$dir/Helpers.ini") || ($made = MoneyServerConfig::ensure($dir)) === null) {
        continue;
    }
    echo "opensim: wrote $made\n";
}
