<?php

declare(strict_types=1);

/**
 * The old global names of the engine: the helpers and the plugins still use them while they move to the namespaces.
 */

use OpenSim\Archives\Oar;
use OpenSim\Config\GridConfig;

describe('Legacy names', function () {
    test('are a thin class of the one they stand for, loaded when asked', function () {
        expect(class_exists('OpenSim_Oar'))->toBeTrue()
            ->and(is_subclass_of('OpenSim_Oar', Oar::class))->toBeTrue()
            ->and(class_exists('OpenSim_Config'))->toBeTrue()
            ->and(is_subclass_of('OpenSim_Config', GridConfig::class))->toBeTrue();
    });

    test('are nothing for a name nobody knows', function () {
        expect(class_exists('OpenSim_Nothing'))->toBeFalse()->and(class_exists('OpenSim_Nothing_Either'))->toBeFalse();
    });
});
