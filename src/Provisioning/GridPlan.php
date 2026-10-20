<?php

namespace OpenSim\Provisioning;

use OpenSim\Config\ConnectionString;

/**
 * Everything gathered to (re)configure one grid, before anything is written.
 */
final class GridPlan
{
    use PlanVariables;

    public string $gridName = '';
    public string $gridNick = '';
    public string $gridSlug = '';
    public bool $enableHypergrid = true;
    /** One simulator is the whole grid (its login, its services, its region): no Robust, no other simulator, one database. */
    public bool $standalone = false;
    /** Free blocks the regions of the grid leave between them (0: side by side). */
    public int $regionSpacing = 0;
    /** The center of the grid, "x,y": where the search of a place for a region starts. */
    public string $center = '';

    // Selected core (multi-version aware).
    public string $coreDirectory = '';
    public string $binDir = '';

    public string $baseHostname = '';
    public int $publicPort = 8002;
    public int $privatePort = 8003;
    public string $webUrl = '';

    // The console: 'rest' (remote, through its port: from another machine or a
    // container) or 'screen' (a session to attach on this machine).
    public string $consoleMode = 'rest';
    public int $consolePort = 0;
    /** The name clients use to reach the console (ConsoleHost, read by the helpers): the address of the machine. */
    public string $consoleHost = '';
    public string $consoleUser = '';
    public string $consolePass = '';

    /** The helpers of the grid (economy, search, offline messages) are served by the web site. */
    public bool $helpers = false;
    /** Where the helpers are on the web site: the path of the economy the viewers are given. */
    public string $helpersPath = '/helpers';

    // The options of a grid that is made (see Models\Grid::fields(), group options).
    /** Where the search is: 'internal' (the helpers of the grid), 'shared' (the provider of 2do.directory) or 'disabled'. */
    public string $search = 'internal';
    /** The offline messages: 'helpers' (module V1, the helpers keep them) or 'robust' (module V2, Robust keeps them). */
    public string $offline = 'helpers';
    /** The currency: 'beta' (BetaGridLikeMoneyModule) or 'gloebit'. */
    public string $economy = 'beta';
    /** More OSSL functions for the owners and the groups of the parcels (NPC, animations, drawing...). */
    public bool $ossl = false;
    /** @var list<string> The options the grid has (see GridOptions): the presets that are on, by name. */
    public array $options = [];
    /** The web server the configuration is written for (caddy, apache, nginx), and what it serves: 'helpers' or 'website'. */
    public string $webServer = 'caddy';
    public string $webSite = 'helpers';

    // Database.
    public string $dbHost = 'localhost';
    public string $dbName = '';
    public string $dbUser = 'opensim';
    public string $dbPass = 'changeme';

    // Derived per-grid locations (filled from the active install profile).
    public string $gridDir = '';
    public string $etcDirectory = '';
    public string $dataDirectory = '';
    public string $cacheDirectory = '';
    public string $logsDirectory = '';

    // What to do once written, asked before anything is written.
    public bool $enable = true;
    public bool $start = true;

    /** The plan as plain values, to hand it to the process that writes it. */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $values */
    public static function fromArray(array $values): self
    {
        $plan = new self();
        foreach ($values as $name => $value) {
            if (property_exists($plan, $name)) {
                $plan->$name = $value;
            }
        }

        return $plan;
    }

    public function robustIni(): string
    {
        return $this->gridDir . '/' . ($this->enableHypergrid ? 'Robust.HG.ini' : 'Robust.ini');
    }

    public function connectionString(): string
    {
        return (string) ConnectionString::for($this->dbHost, $this->dbName, $this->dbUser, $this->dbPass);
    }

    protected function derived(): array
    {
        $version = preg_replace('/^opensim-/', '', basename($this->coreDirectory)) ?? '';

        return [
            'connection_string' => $this->connectionString(),
            'opensim_version' => $version,
            'runtime' => version_compare($version, '0.9.3.0', '>=') ? 'dotnet' : 'mono',
            'log_base' => $this->logsDirectory . '/' . Slug::snake($this->gridSlug) . '_robust',
            'console_rest' => $this->consoleMode === 'rest' && $this->consolePort > 0 ? 'true' : '',
        ];
    }
}
