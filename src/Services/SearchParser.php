<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Data\OSPDO;
use OpenSim\Network\Http;
use OpenSim\Text\Uuid;

/**
 * Reads what the registered simulators tell (the data snapshot of their DataSnapshot module) into the tables of the search: the
 * regions, the parcels shown in search, the land for sale, the popular places, the objects. A simulator that does not answer is
 * looked at less and less often, then forgotten.
 *
 *     $parser = new SearchParser($registry, $db, 'regionsregister', $environment->http);
 *     $parser->parse();                  // the hosts that are due
 */
final class SearchParser
{
    /** A host that failed more than this many times in a row is forgotten. */
    public const MAX_FAILURES = 10;

    /**
     * @param string $regions the table of the regions of the search
     * @param ?\Closure(string):?string $http the body of an address, null when it does not answer (default: curl)
     */
    public function __construct(
        private readonly SearchRegistry $registry,
        private readonly OSPDO $db,
        private readonly string $regions,
        private readonly ?\Closure $http = null,
    ) {}

    /**
     * Read the snapshot of the hosts that are due.
     *
     * @return int the hosts looked at
     */
    public function parse(): int
    {
        function_exists('set_time_limit') && @set_time_limit(0);
        $hosts = $this->registry->due();
        foreach ($hosts as ['host' => $host, 'port' => $port]) {
            $this->check($host, $port);
        }

        return count($hosts);
    }

    /** Look at a host: read its snapshot, or count a failure and wait longer for the next time. */
    public function check(string $host, int $port): void
    {
        $url = "http://$host:$port/?method=collector";
        $xml = $this->http !== null ? ($this->http)($url) : Http::get($url);
        $failures = 0;
        $interval = SearchRegistry::INTERVAL;
        if (!is_string($xml) || $xml === '') {
            $failures = $this->registry->failures($host, $port) + 1;
            $interval = SearchRegistry::backoff($failures);
            if ($failures > self::MAX_FAILURES) {
                $this->registry->unregister($host, $port);

                return;
            }
        }
        $this->registry->schedule($host, $port, $this->registry->now() + $interval, $failures);
        is_string($xml) && $xml !== '' && $this->scan($host, $port, $xml);
    }

    /**
     * Read a snapshot into the tables. What is not a snapshot (an error page, a snapshot without regions) is ignored.
     */
    public function scan(string $host, int $port, string $xml): void
    {
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $regiondata = $loaded ? $document->getElementsByTagName('regiondata')->item(0) : null;
        if ($regiondata === null) {
            return;
        }
        $gatekeeper = self::text($document->documentElement, 'gatekeeperURL');

        // The host is read again after the time its snapshot says it is valid
        $this->db->prepareAndExecute(
            'UPDATE hostsregister SET nextcheck = :next, gatekeeperURL = :gatekeeper WHERE host = :host AND port = :port',
            [
                'next' => $this->registry->now() + (int) self::text($regiondata, 'expire'),
                'gatekeeper' => $gatekeeper,
                'host' => $host,
                'port' => $port,
            ],
        );
        foreach ($regiondata->getElementsByTagName('region') as $region) {
            $this->region($region, $gatekeeper);
        }
    }

    private function region(\DOMElement $region, string $gatekeeper): void
    {
        $info = $region->getElementsByTagName('info')->item(0);
        $data = $region->getElementsByTagName('data')->item(0);
        if ($info === null || $data === null) {
            return;
        }
        $uuid = self::text($info, 'uuid');
        // What the search had of this region is replaced by what it says now
        $this->db->prepareAndExecute("DELETE FROM {$this->regions} WHERE regionUUID = ?", [$uuid]);
        $this->registry->forgetRegion($uuid);

        $estate = $data->getElementsByTagName('estate')->item(0);
        $parentEstate = $estate === null ? '' : self::text($estate, 'id');
        $owner = $estate === null ? '' : self::text($estate, 'uuid');
        $this->db->insert($this->regions, [
            'regionname' => self::text($info, 'name'),
            'regionUUID' => $uuid,
            'regionhandle' => self::text($info, 'handle'),
            'url' => self::text($info, 'url'),
            'owner' => $estate === null ? '' : self::text($estate, 'name'),
            'ownerUUID' => $owner === '' ? Uuid::NULL_KEYS[0] : $owner,
            'gatekeeperURL' => $gatekeeper,
        ]);
        $mature = $region->getAttribute('category');
        foreach ($data->getElementsByTagName('parcel') as $parcel) {
            $this->parcel($parcel, $uuid, $mature, $parentEstate, $gatekeeper);
        }
        foreach ($data->getElementsByTagName('object') as $object) {
            $this->db->insert('objects', [
                'objectuuid' => self::text($object, 'uuid'),
                'parceluuid' => self::text($object, 'parceluuid'),
                'location' => self::text($object, 'location'),
                'name' => self::text($object, 'location'),
                'description' => self::text($object, 'description'),
                'regionuuid' => self::text($object, 'regionuuid'),
                'gatekeeperURL' => $gatekeeper,
            ]);
        }
    }

    private function parcel(
        \DOMElement $parcel,
        string $region,
        string $mature,
        string $estate,
        string $gatekeeper,
    ): void {
        $null = Uuid::NULL_KEYS[0];
        $name = self::text($parcel, 'name');
        $uuid = self::text($parcel, 'uuid');
        $info = self::text($parcel, 'infouuid');
        $landing = self::text($parcel, 'location');
        $area = self::text($parcel, 'area');
        $dwell = self::text($parcel, 'dwell');
        // The image tag only exists when the parcel has a snapshot
        $image = self::text($parcel, 'image');
        $image = $image === '' ? $null : $image;
        $ownerNode = $parcel->getElementsByTagName('owner')->item(0);
        $groupNode = $parcel->getElementsByTagName('group')->item(0);
        $group = $groupNode === null ? '' : self::text($groupNode, 'groupuuid');
        $forSale = $parcel->getAttribute('forsale') === 'true';

        // The popular places forget the parcels that are no longer shown in search, the table of all parcels holds one row each
        $this->db->prepareAndExecute('DELETE FROM popularplaces WHERE parcelUUID = ?', [$uuid]);
        $this->db->prepareAndExecute('DELETE FROM allparcels WHERE parcelUUID = :parcel', ['parcel' => $uuid]);
        $this->db->insert('allparcels', [
            'regionUUID' => $region,
            'parcelname' => $name,
            'ownerUUID' => $ownerNode === null ? $null : self::text($ownerNode, 'uuid'),
            'groupUUID' => $group === '' ? $null : $group,
            'landingpoint' => $landing,
            'parcelUUID' => $uuid,
            'infoUUID' => $info,
            'parcelarea' => $area,
            'gatekeeperURL' => $gatekeeper,
        ]);
        if ($parcel->getAttribute('showinsearch') === 'true') {
            $this->db->insert('parcels', [
                'regionUUID' => $region,
                'parcelname' => $name,
                'parcelUUID' => $uuid,
                'landingpoint' => $landing,
                'description' => self::text($parcel, 'description'),
                'searchcategory' => $parcel->getAttribute('category'),
                'build' => $parcel->getAttribute('build'),
                'script' => $parcel->getAttribute('scripts'),
                'public' => $parcel->getAttribute('public'),
                'dwell' => $dwell,
                'infouuid' => $info,
                'mature' => $mature,
                'gatekeeperURL' => $gatekeeper,
                'imageUUID' => $image,
            ]);
            // Land for sale is not a popular place
            $forSale ||
                $this->db->insert('popularplaces', [
                    'parcelUUID' => $uuid,
                    'name' => $name,
                    'dwell' => $dwell,
                    'infoUUID' => $info,
                    'has_picture' => $image !== $null ? 1 : 0,
                    'mature' => $mature,
                    'gatekeeperURL' => $gatekeeper,
                ]);
        }
        $forSale &&
            $this->db->insert('parcelsales', [
                'regionUUID' => $region,
                'parcelname' => $name,
                'parcelUUID' => $uuid,
                'area' => $area,
                'saleprice' => $parcel->getAttribute('salesprice'),
                'landingpoint' => $landing,
                'infoUUID' => $info,
                'dwell' => $dwell,
                'parentestate' => $estate,
                'mature' => $mature,
                'gatekeeperURL' => $gatekeeper,
            ]);
    }

    /** The text of the first element of a name inside another, empty when there is none. */
    private static function text(?\DOMElement $parent, string $tag): string
    {
        return trim((string) $parent?->getElementsByTagName($tag)->item(0)?->nodeValue);
    }
}
