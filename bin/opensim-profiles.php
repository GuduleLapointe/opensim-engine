<?php

/**
 * opensim-profiles: what the packages do to opensim.conf when they are installed, upgraded or removed (the postinst scripts run it):
 *
 *   opensim-profiles.php [--conf FILE] [--cores FOLDER] [--default-modules "a b"]
 *
 * One profile per release found in the cores folder (/usr/share/opensim), the one of a release that is gone forgotten, the latest
 * release the default when there is none. With --default-modules, the modules are enabled for the install if it has no setting for
 * them yet.
 */

declare(strict_types=1);

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

use OpenSim\Config\PackagedProfiles;

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;

        break;
    }
}
if (!class_exists(PackagedProfiles::class)) {
    fwrite(STDERR, "opensim-profiles: the autoload of the engine is not found\n");
    exit(2);
}

$options = getopt('', ['conf:', 'cores:', 'default-modules:']);
$profiles = new PackagedProfiles($options['conf'] ?? '/etc/opensim/opensim.conf', $options['cores'] ?? '/usr/share/opensim');
foreach ($profiles->update() as $note) {
    echo "opensim: $note\n";
}
if (isset($options['default-modules']) && $profiles->enableModulesOnce((string) $options['default-modules'])) {
    echo "opensim: modules enabled: {$options['default-modules']}\n";
}
