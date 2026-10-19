<?php

declare(strict_types=1);

/**
 * The archives and the backups of the grids: found in the data folder of their grid and in the folder of the backups, described by
 * their names.
 */

use OpenSim\Models\Archive;
use OpenSim\Models\Grid;
use OpenSim\Testing\Machine;

/** The test machine with an inventory, two regions and a backup, and the first archive found. */
function archive_machine(): Archive
{
    return archive_files()[1]->all[0];
}

/** @return array{0:Machine,1:object} */
function archive_files(): array
{
    $machine = new Machine();
    $grid = Grid::find($machine->environment, 'demo');
    $data = rtrim($grid->dataDirectory(), '/');
    $machine->write("$data/backups/iar/demo-ada-love-noassets-20261019-101500.iar", 'iar');
    $machine->write("$data/backups/oar/demo-alpha-Plaza-perm_CM-publish-20261019-111500.oar", 'oar');
    $machine->write("$data/backups/oar/demo-alpha-20261019-121500.oar", 'oar');
    $backups = rtrim($machine->environment->layout->dataRoot(), '/') . '/backups/admin';
    $machine->write("$backups/demo-alpha-20261019-131500-logs.tar.gz", 'tgz');
    $machine->write("$backups/other-20261019-131500.tar.gz", 'tgz');

    return [$machine, (object) ['all' => Archive::all($machine->environment, ['sort' => 'name'])->items()]];
}

describe('Archives', function () {
    test(
        'are the inventories and regions of the data folder of their grid, and the backups of the setup, by their names',
        function () {
            [$machine, $found] = archive_files();
            $by = [];
            foreach ($found->all as $archive) {
                $by[$archive->name()] = $archive->detail();
            }

            expect($by)
                ->toHaveKeys(['demo-alpha-20261019-121500.oar', 'demo-alpha-20261019-131500-logs.tar.gz'])
                ->and($by['demo-ada-love-noassets-20261019-101500.iar'])
                ->toMatchArray([
                    'kind' => 'iar',
                    'grid' => 'demo',
                    'user' => 'ada love',
                    'assets' => false,
                    'size' => 3,
                ])
                ->and($by['demo-alpha-Plaza-perm_CM-publish-20261019-111500.oar'])
                ->toMatchArray([
                    'kind' => 'oar',
                    'simulator' => 'alpha',
                    'region' => 'Plaza',
                    'permissions' => '_CM',
                    'publish' => true,
                ])
                ->and($by['demo-alpha-20261019-121500.oar'])
                ->toMatchArray(['kind' => 'oar', 'simulator' => 'alpha', 'region' => null, 'assets' => true])
                ->and($by['demo-alpha-20261019-131500-logs.tar.gz'])
                ->toMatchArray(['kind' => 'backup', 'simulator' => 'alpha', 'logs' => true, 'archives' => false])
                ->and(count($by))
                ->toBe(4)
                ->and(Archive::all($machine->environment, ['filters' => ['kind' => 'oar']])->total())
                ->toBe(2)
                ->and(Archive::find($machine->environment, 'demo_backup_demo-alpha-20261019-131500-logs')?->name())
                ->toBe('demo-alpha-20261019-131500-logs.tar.gz');
        },
    );
});
