<?php

declare(strict_types=1);

use OpenSim\Provisioning\GridOptions;

describe('An option that is an ini file', function () {
    /** A file of a grid, as the core has it: everything commented. */
    function option_target(): string
    {
        $file = test_tmp() . '/option-' . bin2hex(random_bytes(4)) . '.ini';
        file_put_contents(
            $file,
            "[OSSL]\n  ;AllowOSFunctions = true\n  AllowOSFunctions = true\n  ; osslParcelO = \"PARCEL_OWNER,\"\n  ; osslParcelOG = \"PARCEL_GROUP_MEMBER,PARCEL_OWNER,\"\n",
        );

        return $file;
    }

    test('is combined into a file of the grid, once', function () {
        $target = option_target();

        expect(GridOptions::combine('ossl-extended', $target))->toBe($target);
        $text = (string) file_get_contents($target);
        expect($text)
            ->toMatch('/^\s*osslParcelO = "PARCEL_OWNER,"$/m')
            ->toMatch('/^\s*osslParcelOG = "PARCEL_GROUP_MEMBER,PARCEL_OWNER,"$/m')
            ->toMatch('/^\s*AllowOSFunctions = true$/m')
            // Nothing more to do the second time
            ->and(GridOptions::combine('ossl-extended', $target))
            ->toBeNull();
    });

    test('does nothing for an option or a file that is not there', function () {
        expect(GridOptions::combine('nothing', option_target()))
            ->toBeNull()
            ->and(GridOptions::combine('ossl-extended', '/nowhere/osslEnable.ini'))
            ->toBeNull();
    });
});
