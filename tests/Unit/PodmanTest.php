<?php

use OpenSim\Container\Podman;

function podman_stub(): string
{
    $directory = test_tmp() . '/podman-stub-' . bin2hex(random_bytes(5));
    mkdir($directory, 0o755, true);
    $binary = $directory . '/podman';
    file_put_contents(
        $binary,
        <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(__DIR__ . '/arguments.json', json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR));
$input = stream_get_contents(STDIN);
file_put_contents(__DIR__ . '/input', $input === false ? '' : $input);
if (($argv[3] ?? '') === 'inspect') {
    exit(1);
}
echo "stub output\n";
PHP,
    );
    chmod($binary, 0o755);

    return $binary;
}

describe('Podman', function () {
    test('container operations preserve arguments and use the requested connection', function () {
        $binary = podman_stub();
        $directory = dirname($binary);
        $podman = new Podman($binary, 'ursull');

        $created = $podman->create(
            'test-container',
            'debian:12',
            ['org.opensim-cli.deploy' => '1'],
            null,
            ['sh', '-c', 'while :; do sleep 3600; done'],
        );

        expect($created->status)->toBe(0);
        expect(json_decode((string) file_get_contents($directory . '/arguments.json'), true))->toBe([
            '--connection',
            'ursull',
            'create',
            '--name',
            'test-container',
            '--label',
            'org.opensim-cli.deploy=1',
            'debian:12',
            'sh',
            '-c',
            'while :; do sleep 3600; done',
        ]);

        $executed = $podman->exec('test-container', ['cat'], "contents\n");
        expect($executed->status)->toBe(0);
        expect(json_decode((string) file_get_contents($directory . '/arguments.json'), true))->toBe([
            '--connection',
            'ursull',
            'exec',
            '-i',
            'test-container',
            'cat',
        ]);
        expect(file_get_contents($directory . '/input'))->toBe("contents\n");
        expect($podman->remove('test-container', true)->status)->toBe(0);

        unlink($directory . '/arguments.json');
        unlink($directory . '/input');
        unlink($binary);
        rmdir($directory);
    });
});
