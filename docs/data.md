# The data of a grid

The engine reads a grid's data. An application (a CMS, a plugin, a command, the manager) asks the engine and shows what it gets: it
never queries the databases of OpenSimulator itself.

```php
use OpenSim\Config\GridConfig;
use OpenSim\Data\Database;
use OpenSim\Models\{Avatar, Parcel, Region};

$config = GridConfig::fromFolder('/etc/opensim/grids/mygrid');   // the files of the grid, as OpenSimulator reads them
```

## Models

`Avatar`, `Region` and `Parcel` offer the same standard names, whatever their tables call things (`OpenSimModelInterface`):

| | |
| --- | --- |
| `id() name() slug() uuid() uri()` | what identifies it, what is shown, the name made for an address, where it is reached from other grids |
| `detail()` | every field, under the standard names, as an array |
| `owner() group() flags() lastSeen()` | the owner (UUID), the group that owns it, the bitwise flags, the last visit |
| `isEnabled() enable() disable() update() delete()` | a write goes through a channel and is refused with `ChannelUnavailable` when none can do it (`channels()` lists them) |
| `Model::all($config, $options)` | a `Collection` |
| `Model::find($config, $idOrName)` | one record, or null |

```php
$avatars = Avatar::all($config, ['search' => 'ada', 'sort' => 'lastSeen desc', 'filters' => ['online' => 'yes'], 'page' => 2, 'perPage' => 20]);
$avatar = Avatar::find($config, 'Ada Love');          // or its UUID
$avatar->isGod(); $avatar->isOnline(); $avatar->lastSeen(); $avatar->uri();   // ada.love@play.example.org:8002

$regions = Region::all($config, ['filters' => ['online' => 'yes']]);   // the links to other grids only with ['hyperlink' => 'yes']
$region = Region::find($config, 'Welcome');
$region->isDefault(); $region->has(Region::PERSISTENT); $region->width();

$parcels = Parcel::all($config, ['filters' => ['shown' => 'yes'], 'sort' => 'dwell desc']);   // the land of every simulator
Parcel::at($config, $region->uuid(), 120, 40);          // the parcel that holds a point of a region
```

The filters and sorts of each model are in the collection it gives back (`filters()`, `columns()`).

The flags are constants of the model, with the value and the name OpenSimulator gives them: `Region::DEFAULT_REGION`,
`Region::FALLBACK_REGION`, `Region::REGION_ONLINE`... (`Region::flagName()` is the name in the config of Robust, `DefaultRegion`; `Region::flagNames($flags)` the names of the flags of a value that can be given there, `$region->roles()` those of a region),
`Parcel::ALLOW_FLY`, `Parcel::SHOW_DIRECTORY`... (the names of the constants of LSL).

## Making and changing an avatar

```php
$avatar = Avatar::create($config, ['name' => 'Ada Love', 'email' => 'ada@example.org', 'password' => 'secret']);   // account, password, inventory, outfit
$avatar->update(['password' => 'new', 'home' => $regionId, 'about' => 'Hello', 'level' => 0]);
```

Each row is written in the database of its service (the accounts, the passwords, the inventories, the home, the profile may be in different ones), the account last. `passwordHash` (`['hash' => ..., 'salt' => ...]`) and `uuid` serve an account that is moved. The name does not change.

## Services

A grid has services (assets, inventories, profiles, login...), served by its Robust instances or declared for its simulators. A standalone grid serves them with its simulator.

```php
$grid->services();                       // a Collection of Service
$grid->service('asset')->provider();     // the controller that serves it, null when none does
$grid->service('profile')->isExternal(); // declared, served somewhere else
$simulator->service('profile')->url();   // as this simulator reaches it: its own config, else its grid's
```

## What a grid says of itself

`$grid->gridInfo()` gives the keys of the `[GridInfoService]` of Robust (gridname, login, economy, welcome, search...), as the
running Robust says them at `/get_grid_info`: that is what answers a request, even when the config was changed after the grid
started and waits for a restart. When the grid does not answer, they are the ones of its config, and `$grid->gridInfoIsLive()` says
which. The addresses the viewers are told (the economy, for the currency and the land purchase) follow it.

```php
$grid->gridInfo()['login'];       // http://play.example.org:8002/
$grid->gridInfoIsLive();          // false: the grid did not answer, this is its config
$grid->service('currency')->url();   // the economy it tells, then currency.php
```

## The login address of a grid

What a grid says of itself at get_grid_info is the truth about it, whatever address it was reached at: a grid that changed its domain
name says the new one when it is asked at the old one, and the new one is its login. `$grid->loginUrl()` is that login, `$grid->uri()`
its host and port, `$grid->regionUri('Welcome', '128/128/22')` the address of one of its regions, and the address of a visitor of
another grid follows it (`Avatar::visitor($config, $id, $grids)`: `first.last@host:port` of the login their grid says, not of the
address OpenSimulator kept when they came).

What a grid says is kept when it is verified (`Network\GridInfoCache`): joining a grid, starting it, `$grid->refreshInfo()`. Nothing is
asked anywhere else, `gridInfo()`, `loginUrl()` and `uri()` read what was kept, valid for a day (a grid that is offline for hours does not
make it less valid, a verification that fails leaves it as it is), then it is gone. No other address is tried in place of the one of a
grid: a grid that runs elsewhere and was never verified, or whose time is over, has no login (`loginUrl()` is null). The engine adds no
port the grid does not give.

## Cache

`Data\Cache` keeps what takes a search to know (an answer of a grid, a row of a database, a file): in memory for the session, so the
same call is never searched twice, and in a store for a while when it is given a time to live. The store is the `oshelpers_cache` table
of the search database of the grid, the one the helpers and the aggregator use (`Cache::for($environment, $config)`), else files in the
cache folder of the install (`Cache::for($environment)`).

    $cache->set('region.' . $uuid, $row);             // this session only
    $cache->set('region.' . $uuid, $row, 86400);      // a day, for every request (0 for ever)
    $row = $cache->remember('region.' . $uuid, fn() => Region::find($config, $uuid)?->detail(), 3600);

A search that found nothing is held for the session as well. A database that fails is left alone, the memory goes on.

## Addresses of a grid

`$grid->addresses()` gives every address the grid gives or has by default, by the key of the service: `currency`, `landtool`,
`query` (the search API of the simulators), `search` (the web search), `register`, `offline`, `motd`, `guide`, `welcome`, `map`,
`grid-status`, `grid-status-rss`, `parser`, `events-parser`, `text-images`, `assets` (the web assets server), and the services of
Robust. Whoever asks does not need to know which section of which file says it: the running grid first (get_grid_info), then the
config (Robust, OpenSim, `Helpers.ini`), then where the helpers answer. `$grid->address('currency')` is one of them,
`$grid->service('currency')->source()` says where it comes from (`provider`, `simulator`, `live`, `config`, `default`).

The usual ones have a method of their own: `helpersUrl()`, `economy()`, `searchUrl()` (the web search), `searchApi()` (`query.php`),
`registerUrl()`, `offlineUrl()`, `motdUrl()`, `guideUrl()`, `welcomeUrl()`, `mapUrl()`, `gridStatusUrl()`, `gridStatusRss()`, `assetsUrl()`.
They are for the code that uses the engine, the HTTP API has `/services`.

## Assets

`Asset::all($config)` and `Asset::find($config, $uuid)` give the records of the assets, the same for every type (`type()`: texture,
sound, notecard, script, object, mesh...; `data()`: the bytes of the grid). A type that needs more is a class that extends `Asset`
(`Image` converts a picture for the web); `Asset::TYPES` says which. `Asset::fromService()` reads one from the asset service of the
grid, whatever it keeps it in.

`WebAssets::for($grid)->get($uuid, 'png')` gives an asset for a web page: a texture as a picture (the grid keeps JPEG 2000, ImageMagick
converts it, to a width when it is asked), the other types as they are, the picture of nothing for what is not there. What the grid gave and what was converted are
kept for a day in the cache folder of the grid.

## Collection

What every list gives back: `first() last() count() isEmpty()`, `total()` (before the pages), `page() perPage() pages()`,
`sort() search() applied()` (what was applied), `columns() filters()` (what the list offers), and the forms to show it in:
`items()`, `array()`, `json()`, `csv()`. It can be iterated and encoded with `json_encode()`.

A list that cannot be read (the database does not answer) throws `ChannelUnavailable`; a record that is not there is null.

## Databases

`OpenSim\Data\Database` knows the databases a grid has and where each is set. The models use it; an application that needs a
table of its own can too.

| | where it is set (in this order) | holds |
| --- | --- | --- |
| `Robust` | `[DatabaseService]` of Robust | accounts, visits, regions, inventory, friends, groups... |
| `Assets` | `[AssetService]`, then Robust's | the assets |
| `Profiles` | `[UserProfilesService]`, then Robust's | profiles |
| `Offline` | `[Messaging]` of Robust, then Robust's | offline messages |
| `Simulator` (each) | `[SimulationDataStore]`, then `[DatabaseService]` of the simulator | `land` (the parcels), terrain, objects |
| `Estate` (each) | `[EstateDataStore]`, `[DatabaseService] EstateConnectionString`, then the simulator's; when Robust serves the estates (`EstateDataService` in `[ServiceList]`): `[EstateService]` of Robust, then Robust's, for the grid | estates |
| `Economy` | MoneyServer.ini `[MySql]`, or `[Gloebit] GLBSpecificConnectionString`, depending on the module | balances, transactions |
| `Search` | `[Helpers] SearchConnectionString` of Helpers.ini, then Robust's | the tables of OpenSimSearch |

```php
Database::Assets->connection($config);                 // a ConnectionString (host, database, user...), null when the grid says nothing
Database::Estate->connection($config, 'alpha');        // the databases of a simulator take its file name
Database::Search->pdo($config);                        // an OSPDO on it (check ->connected)
Database::service($config, 'GridUserService');        // any service of Robust: its own database, else Robust's
Database::known($config);                              // every database of the grid, each simulator's once per simulator
Database::Simulator->tables();                         // what the engine knows it holds
```

## Connections and tests

`OpenSim\Data\Connections` opens each database once (`Connections::pdo($config, Database::Robust)`). A test replaces them:
`Connections::using(fn ($what, $simulator, $connection) => $pdo)`, with an SQLite `OSPDO` that has the tables of OpenSimulator.

`OSPDO` is the engine's PDO: `new OSPDO($dsn, $user, $password, $options)` as PDO takes it, or `new OSPDO(['host' => ..., 'name' => ...,
'user' => ..., 'pass' => ...], null, null, $options)`. A connection that fails does not throw: `connected` is false and `error` says why.
