<?php

namespace OpenSim\Provisioning;

/**
 * Writes $EtcRoot/grids/<nick>/<nick>.conf — the grid-level config: opensim.conf
 * (core profile) values plus this grid's own. Everything common to the whole
 * grid, nothing instance-specific. The launcher reads this single file to know
 * the runtime/core and the grid's *Directory bases.
 */
final class GridConf
{
    public function path(GridPlan $plan): string
    {
        return "{$plan->gridDir}/{$plan->gridNick}.conf";
    }

    /**
     * The description of a grid whose Robust is on another machine, what a
     * simulator needs to join it.
     *
     * @param array<string,mixed> $grid Grid::describe()
     */
    public function writeRemote(array $grid, ?Presets $presets = null): string
    {
        $option = ($presets ?? new Presets())->options()['remote-grid'] ?? throw new \RuntimeException('The preset remote-grid is not there.');
        $path = "{$grid['directory']}/{$grid['nick']}.conf";
        is_dir(dirname($path)) || @mkdir(dirname($path), 0o755, true);
        file_put_contents(
            $path,
            $option->text($option->files()[0], [
                'grid_name' => (string) $grid['name'],
                'grid_nick' => (string) $grid['nick'],
                'grid_slug' => (string) $grid['slug'],
                'base_hostname' => (string) $grid['baseHostname'],
                'public_port' => (string) $grid['publicPort'],
                'private_port' => (string) $grid['privatePort'],
                'hypergrid' => $grid['hypergrid'] ? 'true' : 'false',
                'preset' => (string) ($grid['preset'] ?? ''),
                'data_directory' => (string) $grid['dataDirectory'],
                'cache_directory' => (string) $grid['cacheDirectory'],
                'logs_directory' => (string) $grid['logsDirectory'],
            ]),
        );

        return $path;
    }
}
