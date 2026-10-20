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
    public function writeRemote(array $grid): string
    {
        $lines = [
            '[Grid]',
            'Remote = true',
            "GridName = {$grid['name']}",
            "GridNick = {$grid['nick']}",
            "slug = {$grid['slug']}",
            "BaseHostname = {$grid['baseHostname']}",
            "PublicPort = {$grid['publicPort']}",
            "PrivatePort = {$grid['privatePort']}",
            'Hypergrid = ' . ($grid['hypergrid'] ? 'true' : 'false'),
            ...($grid['preset'] ?? '') !== '' ? ['Preset = ' . $grid['preset']] : [],
            'DataDirectory = "' . $grid['dataDirectory'] . '"',
            'CacheDirectory = "' . $grid['cacheDirectory'] . '"',
            'LogsDirectory = "' . $grid['logsDirectory'] . '"',
            '',
        ];
        $path = "{$grid['directory']}/{$grid['nick']}.conf";
        is_dir(dirname($path)) || @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    public function write(GridPlan $plan): string
    {
        $version = preg_replace('/^opensim-/', '', basename($plan->coreDirectory)) ?? '';
        $runtime = version_compare($version, '0.9.3.0', '>=') ? 'dotnet' : 'mono';

        $lines = [
            '[Grid]',
            "Runtime = $runtime",
            "GridName = {$plan->gridName}",
            "GridNick = {$plan->gridNick}",
            "slug = {$plan->gridSlug}",
            "OpensimVersion = $version",
            "CoreDirectory = {$plan->coreDirectory}",
            'EtcDirectory = "' . $plan->etcDirectory . '"',
            'DataDirectory = "' . $plan->dataDirectory . '"',
            'CacheDirectory = "' . $plan->cacheDirectory . '"',
            'LogsDirectory = "' . $plan->logsDirectory . '"',
            "RegionSpacing = {$plan->regionSpacing}",
            "Center = {$plan->center}",
            "OfflineMessages = {$plan->offline}",
            ...$plan->standalone
                ? [
                    ';; A standalone: its simulator serves the grid itself, its address and its database are its own',
                    'Standalone = true',
                    'Hypergrid = ' . ($plan->enableHypergrid ? 'true' : 'false'),
                    "BaseHostname = {$plan->baseHostname}",
                    "WebUrl = {$plan->webUrl}",
                    "PublicPort = {$plan->publicPort}",
                    "DatabaseHost = {$plan->dbHost}",
                    "DatabaseName = {$plan->dbName}",
                    "DatabaseUser = {$plan->dbUser}",
                    "DatabasePassword = {$plan->dbPass}",
                ]
                : [],
            '',
        ];

        $path = $this->path($plan);
        is_dir(dirname($path)) || @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, implode("\n", $lines));

        return $path;
    }
}
