<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Data\OSPDO;
use OpenSim\Hypergrid\Destination;
use OpenSim\Hypergrid\Gatekeeper;
use OpenSim\Network\Http;
use OpenSim\Runtime\ClockInterface;
use OpenSim\Text\Encoding;

/**
 * Fills the events of the search from a HYPEvents calendar (`events.json` of a 2do directory, or any implementation of it): the
 * events that did not end an hour ago and whose region answers, as OpenSimSearch shows them in the events of the viewers.
 */
final class EventsParser
{
    /** The owner and the creator of an event, the calendar does not tell. */
    public const NOBODY = '00000000-0000-0000-0000-000000000001';

    /** The categories of the events of the viewers, by the name a calendar gives them. */
    public const CATEGORIES = [
        'discussion' => 18,
        'sports' => 19,
        'live music' => 20,
        'commercial' => 22,
        'nightlife/entertainment' => 23,
        'games/contests' => 24,
        'pageants' => 25,
        'education' => 26,
        'arts and culture' => 27,
        'charity/support groups' => 28,
        'miscellaneous' => 29,
        // From the HYPEvents code
        'art' => 27,
        'fair' => 23,
        'lecture' => 27,
        'litterature' => 27,
        'music' => 20,
        'roleplay' => 24,
        'social' => 28,
    ];

    /** @var array{online:\Closure(string):bool,parts:\Closure(string):array<string,mixed>,located:\Closure(string):array<string,mixed>,link:\Closure(string,int):string} */
    private array $places;

    /**
     * @param string $source the address of the calendar (HYPEvents), without `/events.json`
     * @param ?\Closure(string):?string $http the body of an address, null when it does not answer (default: curl)
     * @param ?array<string,\Closure> $places how a place is known: `online` (its region answers), `parts` (host, port, region, position),
     *        `located` (the region on the map), `link` (the text of the links to it); the ones of the hypergrid by default
     */
    public function __construct(
        private readonly OSPDO $db,
        private readonly string $source,
        private readonly ?\Closure $http = null,
        ?array $places = null,
        private readonly string $table = SearchTables::EVENTS,
        private readonly ?ClockInterface $clock = null,
    ) {
        $this->places = ($places ?? []) + [
            'online' => static fn(string $url): bool => Gatekeeper::regionIsOnline($url),
            'parts' => static fn(string $url): array => Destination::sanitize($url, '', true),
            'located' => static fn(string $url): array => (array) Gatekeeper::region($url),
            'link' => static fn(string $url, int $format): string => (string) Destination::format($url, $format),
        ];
    }

    /**
     * Replace the events of the search by the ones of the calendar.
     *
     * @return int the events kept, -1 when the calendar did not answer
     */
    public function parse(): int
    {
        $url = rtrim($this->source, '/') . '/events.json';
        $body = $this->http !== null ? ($this->http)($url) : Http::get($url);
        $calendar = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($calendar) || $calendar === []) {
            error_log("Invalid json received from $url");

            return -1;
        }
        $events = [];
        foreach ($calendar as $event) {
            $row = is_array($event) ? $this->event($event) : null;
            $row !== null && ($events[] = $row);
        }
        $this->db->prepareAndExecute("DELETE FROM {$this->table}");
        foreach ($events as $row) {
            $this->db->insert($this->table, $row) || error_log('error while inserting new events');
        }

        return count($events);
    }

    /** The category number of the names a calendar gives: miscellaneous for a name nobody knows, none for no name. */
    public static function category(mixed $values): int
    {
        if (empty($values)) {
            return 0;
        }
        foreach ((array) $values as $value) {
            if (is_int($value)) {
                return $value;
            }
            if (isset(self::CATEGORIES[strtolower((string) $value)])) {
                return self::CATEGORIES[strtolower((string) $value)];
            }
        }

        return 29;
    }

    /**
     * @param array<string,mixed> $event
     * @return ?array<string,mixed> the row of the table, null for an event that is over, or whose region does not answer
     */
    private function event(array $event): ?array
    {
        $now = (int) ($this->clock?->time() ?? time());
        $start = (int) strtotime((string) ($event['start'] ?? ''));
        if ($start < $now - 3600) {
            return null;
        }
        $end = (int) strtotime((string) ($event['end'] ?? ''));
        $duration = $end > $start ? (int) round(($end - $start) / 60) : 60;
        $duration = $duration > 0 ? $duration : 60;

        $place = (string) ($event['hgurl'] ?? '');
        if (!$this->places['online']($place)) {
            return null;
        }
        $region = $this->places['parts']($place);
        $located = $this->places['located']($place);
        $position = empty($region['pos']) ? [128, 128, 25] : explode('/', (string) $region['pos']);
        if (!empty($located['x']) && !empty($located['y'])) {
            $position[0] += $located['x'];
            $position[1] += $located['y'];
        }
        $links = $this->places['link']($place, Destination::APPTP + Destination::HOP);
        $description = strip_tags(html_entity_decode(Encoding::latin1Only((string) ($event['description'] ?? ''))));

        return [
            'owneruuid' => self::NOBODY,
            'name' => strip_tags(html_entity_decode(Encoding::latin1Only((string) ($event['title'] ?? '')))),
            'creatoruuid' => self::NOBODY,
            'category' => self::category($event['categories'] ?? null),
            'description' => "$links\n\n$description",
            'dateUTC' => $start,
            'duration' => $duration,
            'covercharge' => 0,
            'coveramount' => 0,
            'simname' => $this->places['link']($place, Destination::TXT),
            'parcelUUID' => self::NOBODY,
            'globalPos' => implode(',', $position),
            'eventflags' => 0,
            'gatekeeperURL' => (string) ($region['gatekeeper'] ?? ''),
        ];
    }
}
