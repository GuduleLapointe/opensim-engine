<?php

declare(strict_types=1);

/**
 * bin/opensim-api: the API of the engine on the command line, run as a program.
 */

/** Run the script with these words in an empty home, and give the status, what it prints and what it says. */
function api_script(array $words, array $env = []): array
{
    $home = test_tmp() . '/api-script-' . bin2hex(random_bytes(4));
    mkdir($home);
    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__, 2) . '/bin/opensim-api', ...$words],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $home,
        ['HOME' => $home, 'PATH' => (string) getenv('PATH')] + $env,
    );
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
}

describe('script opensim-api', function () {
    test('gives the form of a grid as JSON, with the standalone and the conditions of its fields', function () {
        [$status, $out] = api_script(['GET', '/forms/grid']);
        $fields = array_column(json_decode($out, true)['data']['attributes']['fields'], null, 'name');

        expect($status)
            ->toBe(0)
            ->and($fields)
            ->toHaveKeys(['standalone', 'private_port'])
            ->and($fields['private_port']['condition'])
            ->toBe(['standalone' => false]);
    });

    test('fails with 1 for a problem of the request, says the usage for no words, and refuses an option it does not know', function () {
        expect(api_script(['GET', '/nowhere'])[0])
            ->toBe(1)
            ->and(api_script([])[2])
            ->toContain('usage:')
            ->and(api_script(['GET', '/forms/grid', '--nope'])[0])
            ->toBe(2);
    });

    test('names the install it cannot read', function () {
        [$status, , $error] = api_script(['GET', '/forms/grid'], ['OPENSIM_CONF' => '/nowhere/opensim.conf']);

        expect($status)->toBe(2)->and($error)->toContain('opensim-api:');
    });
});
