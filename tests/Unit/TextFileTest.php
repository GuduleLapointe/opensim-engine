<?php

/**
 * Files from the OpenSim sources, some shipped with Windows line endings, are
 * brought to Unix ones wherever the kit reads or copies them.
 */


use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\RobustConfig;

describe('Robust config with CRLF', function () {
    test('has none left', function () {
        $bin = textfile_dir();
        file_put_contents(
            "$bin/Robust.HG.ini.example",
            "[Const]\r\n    BaseURL = \"http://127.0.0.1\"\r\n[GridService]\r\n    ; Region_Welcome_Area = \"DefaultRegion\"\r\n",
        );
        $plan = new GridPlan();
        $plan->baseHostname = 'localhost';
        $plan->binDir = $bin;

        $ini = (new RobustConfig())->generate($plan);

        expect($ini)->not->toContain("\r");
        expect($ini)->toContain('MapTileDirectory = ');
    });
});
