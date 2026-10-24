#!/usr/bin/env php
<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/autoload.php';
}
require $autoload;

use OpenSim\Container\Podman;

$arguments = array_slice($_SERVER['argv'], 1);
$connection = getenv('CONTAINER_CONNECTION') ?: null;
if (($arguments[0] ?? '') === '--connection') {
    array_shift($arguments);
    $connection = array_shift($arguments) ?: null;
}

$podman = new Podman(connection: $connection);
$action = array_shift($arguments) ?? '';
$result = match ($action) {
    'image-pull' => count($arguments) === 1 ? $podman->pull($arguments[0]) : null,
    'create' => create($podman, $arguments),
    'start' => count($arguments) === 1 ? $podman->start($arguments[0]) : null,
    'inspect' => count($arguments) === 1 ? $podman->inspect($arguments[0]) : null,
    'running' => count($arguments) === 1 ? $podman->running($arguments[0]) : null,
    'exec' => execute($podman, $arguments),
    'remove' => remove($podman, $arguments),
    default => null,
};

if ($result === null) {
    fwrite(STDERR, "usage: opensim-container [--connection NAME] image-pull IMAGE | create --name NAME --image IMAGE [--memory SIZE] [--label KEY=VALUE ...] -- COMMAND... | start NAME | inspect NAME | running NAME | exec NAME [--input] -- COMMAND... | remove [--force] NAME\n");
    exit(2);
}

fwrite(STDOUT, $result->stdout);
fwrite(STDERR, $result->stderr);
exit($result->status);

function create(Podman $podman, array $arguments): ?OpenSim\Container\CommandResult
{
    $name = $image = $memory = null;
    $labels = [];
    while ($arguments !== []) {
        $option = array_shift($arguments);
        if ($option === '--') {
            break;
        }
        $value = array_shift($arguments);
        if ($value === null) {
            return null;
        }
        match ($option) {
            '--name' => $name = $value,
            '--image' => $image = $value,
            '--memory' => $memory = $value,
            '--label' => $labels[] = $value,
            default => null,
        };
        if (!in_array($option, ['--name', '--image', '--memory', '--label'], true)) {
            return null;
        }
    }
    if ($name === null || $image === null || $arguments === []) {
        return null;
    }

    return $podman->create($name, $image, $labels, $memory, $arguments);
}

function execute(Podman $podman, array $arguments): ?OpenSim\Container\CommandResult
{
    $name = array_shift($arguments);
    if ($name === null) {
        return null;
    }
    $readInput = ($arguments[0] ?? '') === '--input';
    $readInput && array_shift($arguments);
    if (array_shift($arguments) !== '--' || $arguments === []) {
        return null;
    }

    $input = $readInput ? stream_get_contents(STDIN) : null;

    return $podman->exec($name, $arguments, $input === false ? null : $input);
}

function remove(Podman $podman, array $arguments): ?OpenSim\Container\CommandResult
{
    $force = ($arguments[0] ?? '') === '--force';
    if ($force) {
        array_shift($arguments);
    }
    if (count($arguments) !== 1) {
        return null;
    }

    return $podman->remove($arguments[0], $force);
}
