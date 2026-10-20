<?php

declare(strict_types=1);

use OpenSim\Archives\Oar;
use OpenSim\Provisioning\ResourceRegistry;

test('the initialization object of a new region is in the engine and packs into an archive', function () {
    $dirs = ResourceRegistry::directories('share/ossl-scripts');
    $sources = array_values(array_filter(array_map(static fn($d) => "$d/fix-parcel-name-src", $dirs), static fn($s) => is_file("$s/archive.xml")));

    expect($sources)->not->toBe([]);

    $archive = sys_get_temp_dir() . '/region-init-' . getmypid() . '.oar';
    defined('OPENSIM_ENGINE') || define('OPENSIM_ENGINE', true);
    Oar::pack($sources[0], $archive);

    expect(filesize($archive))->toBeGreaterThan(0);
    @unlink($archive);
});
