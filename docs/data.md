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
