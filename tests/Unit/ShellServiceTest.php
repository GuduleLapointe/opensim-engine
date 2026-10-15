<?php

use OpenSim\Runtime\ShellService;

describe('output of the commands', function () {
    afterEach(fn() => ShellService::showOutputWith(null));

    test('goes where it is sent, with the status of the command, when something takes it', function () {
        $said = '';
        ShellService::showOutputWith(static function (string $chunk) use (&$said): void {
            $said .= $chunk;
        });

        $code = ShellService::run("sh -c 'printf one; printf two >&2; exit 3'");
        [$shownCode, $shown] = ShellService::runShown('printf three');

        expect($code)->toBe(3)
            ->and($said)->toContain('one')->toContain('two')->toContain('three')
            ->and([$shownCode, $shown])->toBe([0, 'three']);
    });
});
