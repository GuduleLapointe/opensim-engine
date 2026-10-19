<?php

declare(strict_types=1);

namespace OpenSim\Api;

/**
 * The calls whose format OpenSimulator or the viewers fix, described for the contract: the methods of the search, the currency and the
 * land tools (XML-RPC), what the simulators post to the offline messages, what they send to register. Their address is the grid's to
 * choose (its config says it); their content is not. Each field is `[type, what it is, an example]`.
 *
 * A test checks that the methods described are the ones the services answer.
 */
final class Formats
{
    /** The questions of the search module of the simulators and of the viewers (OpenSimSearch), XML-RPC. */
    public const SEARCH = [
        'dir_places_query' => [
            'summary' => 'The places (parcels shown in search) whose name or description has the words',
            'request' => [
                'text' => ['string', 'The words, separated by spaces', 'rose garden'],
                'flags' => [
                    'integer',
                    'The rating the viewer wants: bits 24 (PG), 25 (Mature), 26 (Adult); bit 10 asks the order by traffic',
                    '33554432',
                ],
                'category' => ['integer', 'The category of the places, 0 for any', '0'],
                'query_start' => ['integer', 'The row to start from, 101 are given at most', '0'],
            ],
            'answer' => [
                'parcel_id' => [
                    'string',
                    'The identifier the viewer shows the place by',
                    '44444444-4444-4444-8444-444444444444',
                ],
                'name' => ['string', 'The name of the parcel', 'Rose garden'],
                'for_sale' => ['string', 'Always False: the land for sale is another question', 'False'],
                'auction' => ['string', 'Always False', 'False'],
                'dwell' => ['number', 'Its traffic', '12.5'],
                'regionname' => [
                    'string',
                    'The region it is in; the other columns of the parcel and of its region come with it',
                    'Welcome',
                ],
            ],
        ],
        'dir_popular_query' => [
            'summary' => 'The popular places, by traffic',
            'request' => [
                'text' => ['string', 'Words of the name, empty for all', ''],
                'flags' => ['integer', 'Bit 12 asks the places that have a picture; bits 24 to 26 the rating', '4096'],
                'query_start' => ['integer', 'The row to start from, 100 are given at most', '0'],
                'include_hypergrid' => ['string', '`false` keeps the places of the other grids out', 'true'],
                'include_landsales' => ['string', '`true` adds the land for sale', 'false'],
                'gatekeeper_url' => [
                    'string',
                    'The grid the search is made for, when the address does not say it (`gk`)',
                    '',
                ],
            ],
            'answer' => [
                'parcel_id' => ['string', 'The identifier of the place', '44444444-4444-4444-8444-444444444444'],
                'name' => ['string', 'The name', 'Rose garden'],
                'dwell' => ['number', 'Its traffic', '12.5'],
                'gatekeeperURL' => ['string', 'The grid it belongs to', 'http://grid.example.org:8002'],
                'regionname' => ['string', 'The region it is in', 'Welcome'],
                'regionUUID' => ['string', 'The UUID of the region', '11111111-1111-4111-8111-111111111111'],
                'landingpoint' => ['string', 'Where an avatar lands', '128/128/25'],
                'imageUUID' => ['string', 'Its picture', '77777777-7777-4777-8777-777777777777'],
            ],
        ],
        'dir_land_query' => [
            'summary' => 'The land for sale',
            'request' => [
                'flags' => [
                    'integer',
                    'Bits 24 to 26 the rating; 20 asks a price limit, 21 an area limit; the order is bit 15 (ascending), 16 (price), 18 (area), 19 (name), by price per meter by default',
                    '0',
                ],
                'type' => [
                    'integer',
                    'The kind of land: 4294967295 for any, bits 3 and 4 for the mainland or an estate, auctions are not listed',
                    '4294967295',
                ],
                'price' => ['integer', 'The highest price, when flags asks it', '0'],
                'area' => ['integer', 'The smallest area, when flags asks it', '0'],
                'query_start' => ['integer', 'The row to start from, 101 are given at most', '0'],
            ],
            'answer' => [
                'parcel_id' => ['string', 'The identifier of the parcel', '44444444-4444-4444-8444-444444444444'],
                'name' => ['string', 'Its name', 'Empty lot'],
                'auction' => ['string', 'Always false', 'false'],
                'for_sale' => ['string', 'Always true', 'true'],
                'sale_price' => ['integer', 'Its price', '250'],
                'landing_point' => ['string', 'Where an avatar lands', '128/128/25'],
                'region_UUID' => ['string', 'The region it is in', '11111111-1111-4111-8111-111111111111'],
                'area' => ['integer', 'Its area in square meters', '1024'],
            ],
        ],
        'dir_events_query' => [
            'summary' => 'The events of a day, or the ones to come',
            'request' => [
                'text' => [
                    'string',
                    '`day|category|words`: the day is a number of days from today, or `u` for the events to come and in progress; the category 0 for any',
                    'u|0|jazz',
                ],
                'flags' => ['integer', 'Bits 24 to 26: the rating', '0'],
                'query_start' => ['integer', 'The row to start from, 101 are given at most', '0'],
            ],
            'answer' => [
                'owner_id' => ['string', 'Who organizes it', '00000000-0000-0000-0000-000000000001'],
                'name' => ['string', 'Its title', 'Jazz night'],
                'event_id' => ['integer', 'Its identifier', '1'],
                'date' => [
                    'string',
                    'When it starts, in Pacific time (`m/d h:i A`), as the viewers show it',
                    '11/14 03:13 PM',
                ],
                'unix_time' => ['integer', 'When it starts, Unix time', '1700003600'],
                'event_flags' => ['integer', 'The rating: 0 PG, 1 Mature, 2 Adult', '0'],
                'landing_point' => ['string', 'Where it is on the map of the world (`x,y,z`)', '256010,256020,30'],
            ],
        ],
        'dir_classified_query' => [
            'summary' => 'The classifieds of the profiles, by price of the listing',
            'request' => [
                'text' => ['string', 'Words of the name or of the description', 'shop'],
                'flags' => ['integer', 'Bits 2 (PG), 3 (Mature), 6 (Adult)', '4'],
                'category' => ['integer', 'The category, 0 for any', '0'],
                'query_start' => ['integer', 'The row to start from, 101 are given at most', '0'],
            ],
            'answer' => [
                'classifiedid' => ['string', 'The UUID of the classified', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
                'name' => ['string', 'Its title', 'Shop here'],
                'classifiedflags' => ['integer', 'Its flags', '4'],
                'creation_date' => ['integer', 'Unix time', '1700000000'],
                'expiration_date' => ['integer', 'Unix time', '1707776000'],
                'priceforlisting' => ['integer', 'What the listing costs', '50'],
            ],
        ],
        'event_info_query' => [
            'summary' => 'One event, with all it says',
            'request' => ['eventID' => ['integer', 'The identifier of the event', '1']],
            'answer' => [
                'event_id' => ['integer', 'Its identifier', '1'],
                'creator' => ['string', 'Who made it', '00000000-0000-0000-0000-000000000001'],
                'name' => ['string', 'Its title', 'Jazz night'],
                'category' => [
                    'string',
                    'Its category by name (Discussion, Sports, Live Music, Commercial, Nightlife/Entertainment, Games/Contests, Pageants, Education, Arts and Culture, Charity/Support Groups, Miscellaneous)',
                    'Live Music',
                ],
                'description' => ['string', 'What it is', 'Live jazz'],
                'date' => ['string', 'When it starts, server time', '2026-11-14 23:13:20'],
                'dateUTC' => ['integer', 'When it starts, Unix time', '1700003600'],
                'duration' => ['integer', 'Minutes', '60'],
                'covercharge' => ['integer', 'Whether there is a cover charge', '0'],
                'coveramount' => ['integer', 'How much', '0'],
                'simname' => [
                    'string',
                    'Where it is, as a link; without the grid when it is the grid that asks (`gk`)',
                    'Welcome (grid.example.org:8002)',
                ],
                'globalposition' => ['string', 'Where on the map of the world', '128,128,25'],
                'eventflags' => ['integer', 'The rating', '0'],
            ],
        ],
        'classifieds_info_query' => [
            'summary' => 'One classified, with all it says',
            'request' => [
                'classifiedID' => ['string', 'The UUID of the classified', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
            ],
            'answer' => [
                'classifieduuid' => ['string', 'Its UUID', 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
                'creatoruuid' => ['string', 'Who made it', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'],
                'creationdate' => ['integer', 'Unix time', '1700000000'],
                'expirationdate' => ['integer', 'Unix time', '1707776000'],
                'category' => ['string', 'Its category', '3'],
                'name' => ['string', 'Its title', 'Shop here'],
                'description' => ['string', 'What it is', 'Best shop'],
                'parceluuid' => ['string', 'The parcel it points to', '22222222-2222-4222-8222-222222222222'],
                'parentestate' => ['integer', 'The estate', '1'],
                'snapshotuuid' => ['string', 'Its picture', '77777777-7777-4777-8777-777777777777'],
                'simname' => ['string', 'The region', 'Welcome'],
                'posglobal' => ['string', 'Where', '1,1,1'],
                'parcelname' => ['string', 'The parcel', 'Mall'],
                'classifiedflags' => ['integer', 'Its flags', '4'],
                'priceforlisting' => ['integer', 'What the listing costs', '50'],
            ],
        ],
        'avatar_publish_update' => [
            'summary' =>
                'The "show in search" box of a profile, which OpenSimulator ignores and the module tells here with the secure session of the avatar',
            'request' => [
                'avatar_id' => ['string', 'The UUID of the avatar', 'dddddddd-dddd-4ddd-8ddd-dddddddddddd'],
                'secure_session_id' => [
                    'string',
                    'Its secure session, which only the simulator it is connected to holds',
                    'eeeeeeee-5555-4555-8555-eeeeeeeeeeee',
                ],
                'allow_publish' => ['string', '`true` to show the profile in search', 'true'],
            ],
            'answer' => [],
        ],
    ];

    /** What the viewers and the simulators ask of the currency (`currency.php` of the economy of the grid), XML-RPC. */
    public const CURRENCY = [
        'getCurrencyQuote' => [
            'summary' => 'The viewer asks what an amount of currency costs',
            'request' => [
                'agentId' => ['string', 'The avatar', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'],
                'secureSessionId' => ['string', 'Its secure session', 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee'],
                'currencyBuy' => ['integer', 'The amount', '1000'],
            ],
            'answer' => [
                'success' => ['boolean', 'Whether the avatar proved who they are', 'true'],
                'currency' => [
                    'object',
                    '`estimatedCost` (in cents) and `currencyBuy` (the amount, rounded to the pack of the module when it has some)',
                    '',
                ],
                'confirm' => [
                    'string',
                    'What ties this quote to the purchase: the key of the grid and the address of whoever asked',
                    '5ebe2294ecd0e0f08eab7690d2a6ee69',
                ],
                'errorMessage' => ['string', 'Why not, when it failed', ''],
                'errorURI' => ['string', 'The page of the helpers the viewer opens', ''],
            ],
        ],
        'buyCurrency' => [
            'summary' => 'The viewer buys currency',
            'request' => [
                'agentId' => ['string', 'The avatar', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'],
                'secureSessionId' => ['string', 'Its secure session', 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee'],
                'currencyBuy' => ['integer', 'The amount', '1000'],
                'confirm' => ['string', 'The value the quote gave', '5ebe2294ecd0e0f08eab7690d2a6ee69'],
            ],
            'answer' => [
                'success' => [
                    'boolean',
                    'Whether the currency was added; when it was not, the module of the currency of the grid says what to do (Gloebit: finish on its site, Podex: use its terminals)',
                    'true',
                ],
                'errorMessage' => ['string', 'Why not', ''],
                'errorURI' => ['string', 'What the viewer opens', ''],
            ],
        ],
        'simulatorUserBalanceRequest' => [
            'summary' => 'A simulator asks the balance of an avatar who is on it',
            'request' => [
                'agentId' => ['string', 'The avatar', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'],
                'secureSessionId' => [
                    'string',
                    'Its secure session: the funds are for the one who holds it',
                    'eeeeeeee-5555-4555-8555-eeeeeeeeeeee',
                ],
            ],
            'answer' => [
                'success' => ['boolean', 'Whether the avatar could be authenticated', 'true'],
                'agentId' => ['string', 'The avatar', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'],
                'funds' => ['integer', 'Its balance', '100'],
            ],
        ],
        'regionMoveMoney' => [
            'summary' => 'A region moves money from an avatar to another (a purchase, a gift, a fee)',
            'request' => [
                'agentId' => ['string', 'Who pays', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'],
                'destId' => ['string', 'Who is paid', 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb'],
                'secureSessionId' => [
                    'string',
                    'The secure session of the payer',
                    'eeeeeeee-5555-4555-8555-eeeeeeeeeeee',
                ],
                'regionId' => ['string', 'The region that asks', 'cccccccc-3333-4333-8333-cccccccccccc'],
                'secret' => ['string', 'The secret of the region, which the grid gave it', 'region-secret'],
                'cash' => ['integer', 'The amount', '50'],
                'transactionType' => ['integer', 'The kind of transaction (5001 gift, 5002 object sale...)', '5001'],
                'flags' => ['integer', 'Its flags', '0'],
                'description' => ['string', 'What it is for', 'Pay'],
                'aggregatePermInventory' => ['integer', 'Permissions of what is sold', '0'],
                'aggregatePermNextOwner' => ['integer', 'Permissions of what is sold', '0'],
            ],
            'answer' => [
                'success' => ['boolean', 'Whether the money moved', 'true'],
                'agentId' => ['string', 'The payer', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'],
                'funds' => ['integer', 'The balance of the payer before', '200'],
                'funds2' => ['integer', 'The same', '200'],
                'currencySecret' => ['string', 'A space: nothing', ' '],
            ],
        ],
        'simulatorClaimUserRequest' => [
            'summary' => 'A region claims an avatar: it is the one they are on now',
            'request' => [
                'agentId' => ['string', 'The avatar', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'],
                'secureSessionId' => ['string', 'Its secure session', 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee'],
                'regionId' => ['string', 'The region', 'cccccccc-3333-4333-8333-cccccccccccc'],
                'secret' => ['string', 'The secret of the region', 'region-secret'],
            ],
            'answer' => [
                'success' => ['boolean', 'Whether the region is now the one of the avatar', 'true'],
                'agentId' => ['string', 'The avatar', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'],
                'funds' => ['integer', 'Its balance', '100'],
                'currencySecret' => ['string', 'A space: nothing', ' '],
            ],
        ],
    ];

    /** What the viewers ask of the land tools (`landtool.php` of the economy of the grid), XML-RPC. */
    public const LAND = [
        'preflightBuyLandPrep' => [
            'summary' => 'The viewer asks what buying land would cost, before it does',
            'request' => [
                'agentId' => ['string', 'The avatar', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'],
                'secureSessionId' => ['string', 'Its secure session', 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee'],
                'currencyBuy' => ['integer', 'The currency it lacks', '500'],
                'billableArea' => ['integer', 'The area of the land', '256'],
            ],
            'answer' => [
                'success' => ['boolean', 'Whether the avatar proved who they are', 'true'],
                'currency' => ['object', '`estimatedCost`', ''],
                'membership' => [
                    'object',
                    'No upgrade: `upgrade` false, `action` the page of the helpers, `levels`',
                    '',
                ],
                'landUse' => ['object', '`upgrade` false, `action` the page of the helpers', ''],
                'confirm' => ['string', 'What ties the quote to the purchase', '5ebe2294ecd0e0f08eab7690d2a6ee69'],
            ],
        ],
        'buyLandPrep' => [
            'summary' =>
                'The viewer buys the currency it lacks for the land; the land itself is paid afterwards, by the region (`regionMoveMoney`)',
            'request' => [
                'agentId' => ['string', 'The avatar', 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa'],
                'secureSessionId' => ['string', 'Its secure session', 'eeeeeeee-5555-4555-8555-eeeeeeeeeeee'],
                'currencyBuy' => ['integer', 'The currency it buys', '500'],
                'estimatedCost' => ['integer', 'What the quote said', '200'],
                'billableArea' => ['integer', 'The area of the land', '256'],
                'confirm' => ['string', 'The value the quote gave', '5ebe2294ecd0e0f08eab7690d2a6ee69'],
            ],
            'answer' => [
                'success' => ['boolean', 'Whether the currency was added', 'true'],
                'errorMessage' => ['string', 'Why not', ''],
                'errorURI' => ['string', 'The page of the helpers', ''],
            ],
        ],
    ];

    /** The registration of a simulator to the search: a GET the module of search of the simulators makes. */
    public const REGISTER = [
        'host' => ['string', 'The host of the simulator (not the grid\'s)', 'sim.example.org'],
        'port' => ['integer', 'The HTTP port of the simulator (not the grid\'s)', '9000'],
        'service' => ['string', '`online` when the simulator starts, `offline` when it stops', 'online'],
    ];

    /** What the module V1 of the offline messages posts, and what it gets. */
    public const OFFLINE = [
        'SaveMessage' => [
            'summary' => 'A message for an avatar who is not there is kept',
            'request' =>
                'The `GridInstantMessage` of OpenSimulator, in XML (`fromAgentID`, `fromAgentName`, `toAgentID`, `dialog`, `message`, ...)',
            'answer' => '`<boolean>true</boolean>` when it was kept, `<error>` when it was not',
            'example' =>
                '<?xml version="1.0" encoding="utf-8"?>' .
                "\n" .
                '<GridInstantMessage xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"><fromAgentID>bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb</fromAgentID><fromAgentName>Ada Love</fromAgentName><toAgentID>aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa</toAgentID><dialog>0</dialog><fromGroup>false</fromGroup><message>Hello</message></GridInstantMessage>',
        ],
        'RetrieveMessages' => [
            'summary' => 'The messages kept for an avatar who comes back are given, and forgotten',
            'request' => 'The UUID of the avatar: `<UUID><Guid>...</Guid></UUID>`',
            'answer' => 'An `ArrayOfGridInstantMessage` with the messages, in the XML they were kept in',
            'example' => '<UUID><Guid>aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa</Guid></UUID>',
        ],
    ];

    /**
     * The XML of a call of the method, with the examples of its fields.
     *
     * @param array<string,array{0:string,1:string,2:string}> $fields
     */
    public static function requestExample(string $method, array $fields): string
    {
        return \OpenSim\Services\XmlRpc::request($method, [self::sample($fields)]);
    }

    /**
     * The XML of an answer with the examples of its fields.
     *
     * @param array<string,array{0:string,1:string,2:string}> $fields
     */
    public static function answerExample(array $fields, bool $rows): string
    {
        $sample = self::sample($fields);

        return \OpenSim\Services\XmlRpc::encode(
            $rows ? \OpenSim\Services\XmlRpc::envelope(true, '', [$sample]) : $sample,
        );
    }

    /**
     * A struct with the examples of the fields: numbers and booleans as such.
     *
     * @param array<string,array{0:string,1:string,2:string}> $fields
     * @return array<string,mixed>
     */
    private static function sample(array $fields): array
    {
        $sample = [];
        foreach ($fields as $name => [$type, , $example]) {
            $sample[$name] = match ($type) {
                'integer' => (int) $example,
                'number' => (float) $example,
                'boolean' => $example === 'true',
                'object' => [],
                default => $example,
            };
        }

        return $sample;
    }
}
