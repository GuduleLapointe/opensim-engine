<?php

use OpenSim\Container\Podman;

function podman_test_name(string $caller): string
{
    $host = preg_replace('/[^a-z0-9-]+/', '-', strtolower(gethostname() ?: 'host'));

    return 'opensim-engine-test-' . trim((string) $host, '-') . '-' . $caller . '-' . bin2hex(random_bytes(4));
}

function podman_test_image(): string
{
    return getenv('PODMAN_TEST_IMAGE') ?: 'docker.io/library/alpine:3.22';
}

function podman_test_connection(): ?string
{
    return getenv('CONTAINER_CONNECTION') ?: null;
}

describe('Podman integration', function () {
    test('the PHP library creates and starts a container', function () {
        $podman = new Podman(connection: podman_test_connection());
        $name = podman_test_name('php');
        $created = false;

        try {
            $pulled = $podman->pull(podman_test_image());
            expect($pulled->status)->toBe(0, $pulled->stderr);

            $result = $podman->create($name, podman_test_image(), ['org.opensim-engine.test' => 'php'], null, [
                'sh', '-c', 'while :; do sleep 3600; done',
            ]);
            expect($result->status)->toBe(0, $result->stderr);
            $created = true;

            $started = $podman->start($name);
            expect($started->status)->toBe(0, $started->stderr);
            $running = $podman->running($name);
            expect($running->status)->toBe(0, $running->stderr);
            expect(trim($running->stdout))->toBe('true');
        } finally {
            if ($created) {
                $podman->remove($name, true);
            }
        }
    })->skip(fn() => getenv('PODMAN_INTEGRATION') !== '1', 'set PODMAN_INTEGRATION=1 to create a real Podman container');

    test('a Bash caller creates and starts a container through the engine command', function () {
        $name = podman_test_name('bash');
        $cli = dirname(__DIR__, 2) . '/bin/opensim-container.php';
        $script = __DIR__ . '/podman-create-container.sh';
        $command = ['bash', $script, $cli, $name, podman_test_image()];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue('Could not start the Bash integration script');
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        try {
            expect($status)->toBe(0, $stderr);
            expect(trim((string) $stdout))->toBe('true');
        } finally {
            (new Podman(connection: podman_test_connection()))->remove($name, true);
        }
    })->skip(fn() => getenv('PODMAN_INTEGRATION') !== '1', 'set PODMAN_INTEGRATION=1 to create a real Podman container');
});
