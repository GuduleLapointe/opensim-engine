<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Data\OSPDO;
use OpenSim\Runtime\ClockInterface;
use OpenSim\Text\Uuid;

/**
 * The questions of the search module of the simulators and of the viewers (OpenSimSearch): places, popular places, land for sale,
 * events, classifieds. Each is an XML-RPC method that gets the struct the viewer sent and answers the rows the viewer shows.
 *
 *     $query = new SearchQuery($search, 'regionsregister', profiles: $profiles, gatekeeper: 'grid.example.org:8002');
 *     $rpc = new XmlRpc($query->methods());
 *
 * The search can be shared by several grids: `gatekeeper` (the `gk` argument of the address, which the viewer does not send) tells for
 * which grid the search is made, and keeps the rows of the others out of the answer.
 */
final class SearchQuery
{
    /** The categories of the events, by number. */
    private const EVENT_CATEGORIES = [
        18 => 'Discussion',
        19 => 'Sports',
        20 => 'Live Music',
        22 => 'Commercial',
        23 => 'Nightlife/Entertainment',
        24 => 'Games/Contests',
        25 => 'Pageants',
        26 => 'Education',
        27 => 'Arts and Culture',
        28 => 'Charity/Support Groups',
        29 => 'Miscellaneous',
    ];

    private readonly ?string $gatekeeper;

    /**
     * @param string $regions the table of the regions of the search
     * @param ?OSPDO $profiles the database of the profiles (the classifieds), the grid's
     * @param ?OSPDO $accounts the database of the user accounts, to publish a profile
     * @param ?OSPDO $presence the database of the presence service, who holds the secure sessions
     * @param ?string $gatekeeper the grid the search is made for, as the `gk` argument says it
     */
    public function __construct(
        private readonly OSPDO $search,
        private readonly string $regions,
        private readonly ?OSPDO $profiles = null,
        private readonly ?OSPDO $accounts = null,
        private readonly ?OSPDO $presence = null,
        private readonly ?string $gk = null,
        private readonly ?ClockInterface $clock = null,
    ) {
        $this->gatekeeper = self::gatekeeperUrl($gk);
    }

    /** The address of a gatekeeper as the tables keep it: with its scheme. */
    public static function gatekeeperUrl(?string $gatekeeper): ?string
    {
        $gatekeeper = trim((string) $gatekeeper);

        return $gatekeeper === ''
            ? null
            : (preg_match('#https?://#', $gatekeeper) === 1
                ? $gatekeeper
                : "http://$gatekeeper");
    }

    /** @return array<string,\Closure(array<string,mixed>):(array<string,mixed>|string)> the XML-RPC methods, by name */
    public function methods(): array
    {
        return [
            'dir_places_query' => $this->places(...),
            'dir_popular_query' => $this->popular(...),
            'dir_land_query' => $this->land(...),
            'dir_events_query' => $this->events(...),
            'dir_classified_query' => $this->classifieds(...),
            'event_info_query' => $this->eventInfo(...),
            'classifieds_info_query' => $this->classifiedInfo(...),
            'avatar_publish_update' => $this->publish(...),
        ];
    }

    /**
     * The places (parcels shown in search) whose name or description has the words.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function places(array $request): array
    {
        $flags = (int) ($request['flags'] ?? 0);
        $category = (int) ($request['category'] ?? 0);
        $text = '%' . implode('%', explode(' ', (string) ($request['text'] ?? ''))) . '%';
        if ($text === '%%%') {
            return XmlRpc::envelope(false, 'Invalid search terms');
        }
        $terms = ['(parcelname LIKE :text OR description LIKE :text)'];
        $values = ['text' => $text];
        $rating = $this->rating($flags);
        $rating !== '' && ($terms[] = $rating);
        if ($category > 0) {
            $terms[] = 'searchcategory = :category';
            $values['category'] = $category;
        }
        if ($this->gatekeeper !== null) {
            $terms[] = 'parcels.gatekeeperURL = :gatekeeper';
            $values['gatekeeper'] = $this->gatekeeper;
        }
        // By traffic, else by name
        $order = $flags & 1024 ? 'dwell DESC, parcelname' : 'parcelname';
        $rows = $this->rows(
            $this->search,
            "SELECT * FROM parcels INNER JOIN {$this->regions} AS r ON parcels.regionUUID = r.regionUUID WHERE " .
                implode(' AND ', $terms) .
                " ORDER BY $order LIMIT 101 OFFSET " .
                $this->offset($request),
            $values,
        );
        $data = array_map(
            static fn(array $row): array => array_merge($row, [
                'parcel_id' => $row['infouuid'],
                'name' => $row['parcelname'],
                'for_sale' => 'False',
                'auction' => 'False',
            ]),
            $rows,
        );

        return $data === [] ? XmlRpc::envelope(false, 'No results', $data) : XmlRpc::envelope(true, '', $data);
    }

    /**
     * The popular places, by traffic.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function popular(array $request): array
    {
        $text = (string) ($request['text'] ?? '');
        $flags = (int) ($request['flags'] ?? 0);
        $hypergrid = ($request['include_hypergrid'] ?? null) !== 'false';
        $landSales = ($request['include_landsales'] ?? null) === 'true';
        $terms = [];
        $values = [];
        $flags & (1 << 12) && ($terms[] = 'has_picture = 1');
        $rating = $this->rating($flags, 'pop');
        $rating !== '' && ($terms[] = $rating);
        if ($text !== '') {
            $terms[] = '(name LIKE :text)';
            $values['text'] = "%$text%";
        }
        $gatekeeper = self::gatekeeperUrl((string) ($request['gatekeeper_url'] ?? '')) ?? $this->gatekeeper;
        if (!$hypergrid && $gatekeeper !== null) {
            $terms[] = 'pop.gatekeeperURL = :gatekeeper';
            $values['gatekeeper'] = $gatekeeper;
        }
        $join = '';
        if (!$landSales) {
            $join = 'LEFT JOIN parcelsales AS sales ON sales.parcelUUID = pop.parcelUUID';
            $terms[] = 'sales.regionUUID IS NULL';
        }
        $rows = $this->rows(
            $this->search,
            'SELECT pop.infoUUID, pop.name, pop.dwell, pop.gatekeeperURL, r.regionname, r.regionUUID, par.landingpoint, par.imageUUID FROM popularplaces AS pop ' .
                "INNER JOIN parcels AS par ON pop.parcelUUID = par.parcelUUID INNER JOIN {$this->regions} AS r ON par.regionUUID = r.regionUUID $join" .
                ($terms === [] ? '' : ' WHERE ' . implode(' AND ', $terms)) .
                ' ORDER BY pop.dwell DESC, par.parcelname LIMIT 100 OFFSET ' .
                $this->offset($request),
            $values,
        );

        return XmlRpc::envelope(
            true,
            '',
            array_map(
                static fn(array $row): array => [
                    'parcel_id' => $row['infoUUID'],
                    'name' => $row['name'],
                    'dwell' => $row['dwell'],
                    'gatekeeperURL' => $row['gatekeeperURL'],
                    'regionname' => $row['regionname'],
                    'regionUUID' => $row['regionUUID'],
                    'landingpoint' => $row['landingpoint'],
                    'imageUUID' => $row['imageUUID'],
                ],
                $rows,
            ),
        );
    }

    /**
     * The land for sale.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function land(array $request): array
    {
        $flags = (int) ($request['flags'] ?? 0);
        $type = (int) ($request['type'] ?? 0);
        $terms = [];
        $values = [];
        if ($type !== 4294967295) {
            // Auctions first, to leave quickly: (24 is bits 3 and 4 of SearchTypeFlags)
            if (($type & 26) === 2) {
                return XmlRpc::envelope(false, 'No auctions listed');
            }
            ($type & 24) === 8 && ($terms[] = 'parentestate = 1');
            ($type & 24) === 16 && ($terms[] = 'parentestate <> 1');
        }
        $rating = $this->rating($flags);
        $rating !== '' && ($terms[] = $rating);
        if ($flags & (1 << 20)) {
            $terms[] = 'saleprice <= :price';
            $values['price'] = (int) ($request['price'] ?? 0);
        }
        if ($flags & (1 << 21)) {
            $terms[] = 'area >= :area';
            $values['area'] = (int) ($request['area'] ?? 0);
        }
        if ($this->gatekeeper !== null) {
            $terms[] = 'gatekeeperURL = :gatekeeper';
            $values['gatekeeper'] = $this->gatekeeper;
        }
        // The price per meter is always asked by the map, and makes a good default order
        $order = 'lsq';
        $flags & (1 << 19) && ($order = 'parcelname');
        $flags & (1 << 16) && ($order = 'saleprice');
        $flags & (1 << 18) && ($order = 'area');
        $flags & (1 << 15) || ($order .= ' DESC');
        $rows = $this->rows(
            $this->search,
            'SELECT *, saleprice / area AS lsq FROM parcelsales' .
                ($terms === [] ? '' : ' WHERE ' . implode(' AND ', $terms)) .
                " ORDER BY $order LIMIT 101 OFFSET " .
                $this->offset($request),
            $values,
        );

        return XmlRpc::envelope(
            true,
            '',
            array_map(
                static fn(array $row): array => [
                    'parcel_id' => $row['infoUUID'],
                    'name' => $row['parcelname'],
                    'auction' => 'false',
                    'for_sale' => 'true',
                    'sale_price' => $row['saleprice'],
                    'landing_point' => $row['landingpoint'],
                    'region_UUID' => $row['regionUUID'],
                    'area' => $row['area'],
                ],
                $rows,
            ),
        );
    }

    /**
     * The events of a day (a number of days from today), or the ones to come (`u`), by category and words: `day|category|words`.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function events(array $request): array
    {
        $text = (string) ($request['text'] ?? '');
        $flags = (int) ($request['flags'] ?? 0);
        if ($text === '%%%') {
            return XmlRpc::envelope(false, 'Invalid search terms');
        }
        $pieces = explode('|', $text);
        $day = $pieces[0];
        $category = (int) ($pieces[1] ?? 0);
        $words = count($pieces) < 3 ? '' : $pieces[2];
        $now = $this->now();
        $terms = [];
        $values = [];
        if ($day === 'u') {
            // The events to come and the ones in progress
            $terms[] = 'dateUTC + duration * 60 >= ' . $now;
        } else {
            // The start of the day of the server, moved by the number of days asked
            $now -= (int) date('Z', $now);
            $now -= $now % 86400;
            (int) $day !== 0 && ($now += (int) $day * 86400);
            $terms[] = '(dateUTC + duration * 60 >= ' . $now . ' AND dateUTC < ' . ($now + 86400) . ')';
        }
        if ($category > 0) {
            $terms[] = 'category = :category';
            $values['category'] = $category;
        }
        $ratings = [];
        $flags & (1 << 24) && ($ratings[] = 'eventflags = 0');
        $flags & (1 << 25) && ($ratings[] = 'eventflags = 1');
        $flags & (1 << 26) && ($ratings[] = 'eventflags = 2');
        $ratings === [] || ($terms[] = '(' . implode(' OR ', $ratings) . ')');
        if ($words !== '') {
            $terms[] = '(name LIKE :text1 OR description LIKE :text2)';
            $values['text1'] = $values['text2'] = "%$words%";
        }
        $rows = $this->rows(
            $this->search,
            'SELECT owneruuid, name, eventid, dateUTC, eventflags, globalPos FROM ' .
                SearchTables::EVENTS .
                ($terms === [] ? '' : ' WHERE ' . implode(' AND ', $terms)) .
                ' LIMIT 101 OFFSET ' .
                $this->offset($request),
            $values,
        );

        return XmlRpc::envelope(
            true,
            '',
            array_map(static function (array $row): array {
                $date = new \DateTime('@' . $row['dateUTC']);
                $date->setTimezone(new \DateTimeZone('America/Los_Angeles'));

                // The landing point is only needed to put the events on the map
                return [
                    'owner_id' => $row['owneruuid'],
                    'name' => $row['name'],
                    'event_id' => $row['eventid'],
                    'date' => $date->format('m/d h:i A'),
                    'unix_time' => $row['dateUTC'],
                    'event_flags' => $row['eventflags'],
                    'landing_point' => $row['globalPos'],
                ];
            }, $rows),
        );
    }

    /**
     * The classifieds, by price of the listing.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>|string
     */
    public function classifieds(array $request): array|string
    {
        if (!$this->hasClassifieds()) {
            return XmlRpc::plain(false);
        }
        $text = (string) ($request['text'] ?? '');
        $flags = (int) ($request['flags'] ?? 0);
        $category = (int) ($request['category'] ?? 0);
        if ($text === '%%%') {
            return XmlRpc::envelope(false, 'Invalid search terms', []);
        }
        $terms = [];
        $values = [];
        $ratings = [];
        // PG, Mature and Adult
        foreach ([4, 8, 64] as $bit) {
            $flags & $bit && ($ratings[] = "classifiedflags & $bit");
        }
        $ratings === [] || ($terms[] = '(' . implode(' OR ', $ratings) . ')');
        // Any category is 0
        if ($category > 0) {
            $terms[] = 'category = :category';
            $values['category'] = $category;
        }
        if ($text !== '') {
            $terms[] = '(name LIKE :text OR description LIKE :text)';
            $values['text'] = "%$text%";
        }
        $rows = $this->rows(
            $this->profiles,
            'SELECT * FROM classifieds' .
                ($terms === [] ? '' : ' WHERE ' . implode(' AND ', $terms)) .
                ' ORDER BY priceforlisting DESC LIMIT 101 OFFSET ' .
                $this->offset($request),
            $values,
        );

        return XmlRpc::envelope(
            true,
            '',
            array_map(
                static fn(array $row): array => [
                    'classifiedid' => $row['classifieduuid'],
                    'name' => $row['name'],
                    'classifiedflags' => $row['classifiedflags'],
                    'creation_date' => $row['creationdate'],
                    'expiration_date' => $row['expirationdate'],
                    'priceforlisting' => $row['priceforlisting'],
                ],
                $rows,
            ),
        );
    }

    /**
     * One event, with all it says.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function eventInfo(array $request): array
    {
        $rows = $this->rows($this->search, 'SELECT * FROM ' . SearchTables::EVENTS . ' WHERE eventID = ?', [
            $request['eventID'] ?? 0,
        ]);

        return XmlRpc::envelope(
            true,
            '',
            array_map(function (array $row): array {
                // The grid asking is the one of the event: the name of the region does not need its grid
                $simname =
                    $this->gk !== null && $this->gk !== '' && $this->gk === ($row['gatekeeperURL'] ?? null)
                        ? preg_replace('/^[^ ]*/', '', (string) $row['simname'])
                        : $row['simname'];

                return [
                    'event_id' => $row['eventid'],
                    'creator' => $row['creatoruuid'],
                    'name' => $row['name'],
                    'category' => self::EVENT_CATEGORIES[(int) $row['category']] ?? '*Unspecified*',
                    'description' => $row['description'],
                    'date' => date('o-m-d H:i:s', (int) $row['dateUTC']),
                    'dateUTC' => $row['dateUTC'],
                    'duration' => $row['duration'],
                    'covercharge' => $row['covercharge'],
                    'coveramount' => $row['coveramount'],
                    'simname' => $simname,
                    'globalposition' => $row['globalPos'],
                    'eventflags' => $row['eventflags'],
                ];
            }, $rows),
        );
    }

    /**
     * One classified, with all it says.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>|string
     */
    public function classifiedInfo(array $request): array|string
    {
        if (!$this->hasClassifieds()) {
            return XmlRpc::plain(false);
        }
        $rows = $this->rows($this->profiles, 'SELECT * FROM classifieds WHERE classifieduuid = ?', [
            $request['classifiedID'] ?? '',
        ]);
        $fields = [
            'classifieduuid',
            'creatoruuid',
            'creationdate',
            'expirationdate',
            'category',
            'name',
            'description',
            'parceluuid',
            'parentestate',
            'snapshotuuid',
            'simname',
            'posglobal',
            'parcelname',
            'classifiedflags',
            'priceforlisting',
        ];

        return XmlRpc::envelope(
            true,
            '',
            array_map(static fn(array $row): array => array_intersect_key($row, array_flip($fields)), $rows),
        );
    }

    /**
     * The "show in search" box of a profile. The profile service of Robust gets the box with the rest of the profile and never writes
     * it where OpenSimulator reads it (the first flag of the account), so the search module tells it here. Only the simulator an
     * avatar is connected to holds its secure session, and may.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public function publish(array $request): array
    {
        $avatar = (string) ($request['avatar_id'] ?? '');
        if (!Uuid::isValid($avatar)) {
            throw new Failure('Bad request: avatar_id is not an avatar', 400);
        }
        if (!$this->hasSession($avatar, $request['secure_session_id'] ?? '')) {
            throw new Failure('Forbidden: the secure session is not the one of this avatar', 403);
        }
        $found =
            $this->accounts === null
                ? []
                : $this->rows($this->accounts, 'SELECT UserFlags FROM UserAccounts WHERE PrincipalID = :id', [
                    'id' => $avatar,
                ]);
        if ($found === []) {
            return XmlRpc::envelope(false, 'This avatar has no account', []);
        }
        // The other flags of the account (identified, transacted, age verified...) are not the user's choice here
        $flags =
            ((int) $found[0]['UserFlags'] & ~1) |
            (filter_var($request['allow_publish'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
        $this->accounts?->prepareAndExecute('UPDATE UserAccounts SET UserFlags = :flags WHERE PrincipalID = :id', [
            'flags' => $flags,
            'id' => $avatar,
        ]);

        return XmlRpc::envelope(true, '', []);
    }

    /** Whether the caller holds the secure session of an avatar who is in the grid. */
    private function hasSession(string $avatar, mixed $secure): bool
    {
        if ($this->presence === null) {
            return false;
        }
        $rows = $this->rows($this->presence, 'SELECT SecureSessionID FROM Presence WHERE UserID = :user', [
            'user' => $avatar,
        ]);
        $known = strtolower((string) ($rows[0]['SecureSessionID'] ?? ''));
        $given = is_string($secure) ? strtolower($secure) : '';

        return $known !== '' && !Uuid::isNullKey($known) && $given !== '' && hash_equals($known, $given);
    }

    private function hasClassifieds(): bool
    {
        return $this->profiles !== null && $this->profiles->connected && $this->profiles->table_exists('classifieds');
    }

    /** The condition on the rating of what is looked for: PG, Mature and Adult are bits 24, 25 and 26 of the flags. */
    private function rating(int $flags, string $table = ''): string
    {
        $prefix = $table === '' ? '' : "$table.";
        $terms = [];
        foreach ([24 => 'PG', 25 => 'Mature', 26 => 'Adult'] as $bit => $rating) {
            $flags & (1 << $bit) && ($terms[] = "{$prefix}mature = '$rating'");
        }

        return $terms === [] ? '' : '(' . implode(' OR ', $terms) . ')';
    }

    /** The row to start from: the module sends the page as a string, it goes into the query as a number. */
    private function offset(array $request): int
    {
        return max(0, (int) ($request['query_start'] ?? 0));
    }

    /**
     * @param ?array<string,mixed>|list<mixed> $values
     * @return list<array<string,mixed>>
     */
    private function rows(?OSPDO $db, string $sql, ?array $values = []): array
    {
        $statement = $db?->prepareAndExecute($sql, $values ?: null);

        return $statement ? $statement->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    private function now(): int
    {
        return (int) ($this->clock?->time() ?? time());
    }
}
