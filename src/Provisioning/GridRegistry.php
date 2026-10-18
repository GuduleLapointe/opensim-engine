<?php

namespace OpenSim\Provisioning;

use OpenSim\Config\GridFileService;
use OpenSim\Models\Grid;
use OpenSim\Models\Region;
use OpenSim\Runtime\Environment;

/**
 * What the grid knows of its regions, from the database of its Robust: their
 * ports and their places, whatever machine they run on. It lets a new
 * simulator avoid the ports and the places of the ones that run elsewhere, so
 * that it can move to another machine without changing any of them.
 *
 * Read with the account of the grid; when the database cannot be read from here
 * (a simulator on another machine, with no access to it), only the local files
 * are known.
 */
final class GridRegistry
{
    public function __construct(private DatabaseService $database) {}

    /**
     * The ports the registered regions use: their own (UDP, the one viewers
     * connect to) and the one of their HTTP listener, which is in their URI.
     *
     * @return list<int>|null null when the registry cannot be read
     */
    public function ports(Grid $grid): ?array
    {
        if ($grid->databaseName() === '' || $grid->isPlanned()) {
            return null; // a grid of another machine: its database is not ours to read
        }
        $rows = $this->database->select($grid->databasePlan('GridService'), 'SELECT serverPort, serverURI FROM regions');

        return $rows === null ? null : self::portsOf($rows);
    }

    /**
     * Every port the grids of this machine know of the regions registered in them, the ones served by another machine too: they
     * are no problem for this one, but keeping every port different makes the maintenance easier. The grid given is one of them
     * even when it is not kept here.
     *
     * @param  array<string,string> $profile the setup of the user (EtcRoot)
     * @return list<int>
     */
    public function knownPorts(array $profile, ?Grid $grid = null): array
    {
        $infos = [];
        foreach (\OpenSim\Archives\Instances::grids((string) ($profile['EtcRoot'] ?? '')) as $nick) {
            if (($info = Grid::find(Environment::forProfile($profile), $nick)) !== null) {
                $infos[$nick] = $info;
            }
        }
        if ($grid !== null) {
            $infos[$grid->nick()] = $grid;
        }
        $ports = [];
        foreach ($infos as $info) {
            array_push($ports, ...($this->ports($info) ?? []));
        }

        return array_values(array_unique($ports));
    }

    /**
     * The ports in the rows of the registered regions (`serverPort`, tab, `serverURI`).
     *
     * @param  list<string> $rows
     * @return list<int>
     */
    public static function portsOf(array $rows): array
    {
        $ports = [];
        foreach ($rows as $row) {
            [$internal, $uri] = array_pad(explode("\t", $row), 2, '');
            if (ctype_digit(trim($internal))) {
                $ports[] = (int) $internal;
            }
            if (preg_match('#:(\d+)/?\s*$#', $uri, $m)) {
                $ports[] = (int) $m[1];
            }
        }

        return array_values(array_unique($ports));
    }

    /**
     * The names of the regions the grid has, as far as it is known here: the
     * registry, and the region files of its simulators (a disabled one keeps its
     * name). Names are compared without the case.
     *
     * @return array<string,true> lower case names
     */
    public function names(Grid $grid): array
    {
        $names = [];
        $rows =
            $grid->databaseName() === '' || $grid->isPlanned()
                ? null
                : $this->database->select($grid->databasePlan('GridService'), 'SELECT regionName FROM regions');
        foreach ($rows ?? [] as $row) {
            $names[strtolower(trim($row))] = true;
        }

        return $names + self::fileNames($grid->directory());
    }

    /**
     * The names of the regions described in the files of the simulators of a grid,
     * a disabled one included.
     *
     * @return array<string,true> lower case names
     */
    public static function fileNames(string $gridDir): array
    {
        $names = [];
        foreach (glob("$gridDir/sims/*/regions", GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (array_map(static fn(Region $region): string => $region->name(), Region::inDirectory($dir)) as $name) {
                $names[strtolower($name)] = true;
            }
        }

        return $names;
    }

    /**
     * The places the regions take, in the registry and in the local files of the
     * grid.
     *
     * @return array<string,true>
     */
    public function locations(Grid $grid): array
    {
        $used = [];
        $rows =
            $grid->databaseName() === '' || $grid->isPlanned()
                ? null
                : $this->database->select(
                    $grid->databasePlan('GridService'),
                    "SELECT CONCAT_WS(' ', locX DIV 256, locY DIV 256, sizeX DIV 256, sizeY DIV 256) FROM regions",
                );
        foreach ($rows ?? [] as $row) {
            $n = array_map('intval', preg_split('/\s+/', trim($row)) ?: []);
            if (count($n) === 4) {
                LocationFinder::take($used, $n[0], $n[1], $n[2], $n[3]);
            }
        }
        foreach (glob("{$grid->directory()}/sims/*/regions/*.ini") ?: [] as $file) {
            foreach (preg_split('/^\s*\[/m', GridFileService::read($file)) ?: [] as $section) {
                if (preg_match('/^\s*Location\s*=\s*(\d+)\s*,\s*(\d+)/m', $section, $m)) {
                    $width = preg_match('/^\s*SizeX\s*=\s*(\d+)/m', $section, $w) ? intdiv((int) $w[1], 256) : 1;
                    $height = preg_match('/^\s*SizeY\s*=\s*(\d+)/m', $section, $h) ? intdiv((int) $h[1], 256) : 1;
                    LocationFinder::take($used, (int) $m[1], (int) $m[2], $width, $height);
                }
            }
        }

        return $used;
    }
}
