## Changelog

### Unreleased

- new: `Data\Cache`: what takes a search to know is searched once per session (memory), and kept for a while when given a time to live, in the `oshelpers_cache` table of the search database or in files; `GridInfoCache` is built on it
- new: `Network\GridInfoCache`: what a grid said when it was verified (joining it, starting it, `Grid::refreshInfo()`) is kept for a day and read from there, nothing is asked at each call; `Grid::loginUrl()` and `regionUri()`: the login the grid said is the one, whatever address it was reached at, none for a grid that runs elsewhere and was not verified; the address of a visitor follows it
- new: `Result::isTrue()`, `isFalse()`; `isSuccess()` and `isError()` are no more contraries (null is neither); `Support\Bitmask`; `Connections::test()`; `Destination::loginUrl()`, `sanitizePlus()`; `wantsList` and `skillsList` of an avatar
- new: `Text\Uuid::isNullKey()` (the two keys for nobody) and `isEmpty()`
- new: `Grid::addresses()`, `address()` and `helpersUrl()`, `economy()`, `searchUrl()`, `searchApi()`...: every address of the grid, wherever it is said; the web services of the catalog are `query`, `search`, `welcome`, `web-profile`, `currency`, `landtool`, `register`, `offline`, `motd`, `guide`, `grid-status`, `grid-status-rss`, `parser`, `events-parser`, `text-images`, `assets`, `map`
- new: `Models\Asset` (every type, `Image` for the pictures), `Assets\WebAssets`: pictures converted for the web, resized when asked, and cached; `php-imagick` is required
- new: `Grid::gridInfo()` and `gridInfoIsLive()`: what the running Robust says at get_grid_info, else its config; `Network\GridInfoReader` reads it for everyone
- new: the API of the avatars, regions, parcels and estates of a grid, the tasks, the forms, the writes; `composer docs` makes its documentation
- new: the web services (search, currency, registration, offline, motd, guide) in the catalog of `Service`, fixed or standard
- new: `Api`, `Contract`, `Problem`: JSON:API documents, an OpenAPI 3.1 contract checked by the tests, RFC 9457 errors
- new: `Scheduling\Scheduler` and `Task`: cron and pseudo cron, locked, last run kept
- new: `Avatar::create()` and a fuller `update()`; `AccountImporter::forModels()`
- new: `Models\Service` and `Grid::services()`, `Simulator::service()`; standalone grids (`Standalone = true`)
- update: every operation takes an `Environment` (no profile array); `Ports` is built on one; `EnvironmentFake` for tests
- update: `Instance` and `Robust` are `Controllers\Controller` and `RobustController`; `Core` is `Runtime\Core`
- update: `sims` is `simulators` in the code (`GridConfig::SIMULATORS` names the folder); the region form field is `simulator`
- update: `OSPDO` is `OpenSim\Data\OSPDO` and the health checks `OpenSim\Health\Health`; the `legacy/` aliases (`OpenSim_Config`, `OpenSim_Oar`) are gone, the classes load through the autoloader only
- update: the classes and functions of the WordPress plugin nobody here uses are gone (`OpenSim_Avatar`, `OpenSim_Form`..., the escaping and the dead functions); the plugin has its own copy
- new: `Data\Stats`: the numbers of a grid (members, who is in world, regions, area), from the database of each service
- new: `Models\Parcel` (the `land` of the simulators: list, search, sort, filters, the bitmap of 4 m squares, `Parcel::at()`), its flags as constants
- update: `Models\Region` is a model (list, search, sort, filters, flags as constants named as in OpenSimulator); `RegionFlags` is gone (`Region::roles()`, `rolesIn()`, `giveRoles()`)
- new: `Models\OpenSimModelInterface`, `Models\Avatar` (list, search, sort, filters, pages, one avatar), `Data\Collection` and `Data\Connections`
- new: `Data\Database`, the databases of a grid (services, assets, profiles, offline, simulators, estates, economy, search) and where each is set
- update: the database is reached through PDO (`OSPDO`) only, no `mysql` client is run
- update: one `OSPDO`, the engine's, for the helpers too: DSN or credentials, driver options, `error` when not connected
- new: `GridConfig::withDatabaseDefaults()`: the StorageProvider and ConnectionString OpenSimulator takes from [DatabaseService] for the services, the regions and the estates
- new: `OpenSim\SignIn`: an avatar signs in with the grid itself, never in its database: `PrivateServiceAuthenticator` (accounts and authentication services of the private port), `OpenIdAuthenticator` (the password is asked by the grid), `LoginServiceAuthenticator` (the login of the viewers, which opens a session in the world)
- update: `gettext` is suggested, not required: without it the messages stay in English
- fix: a new region offers every role (a grid can have several default regions) and checks only the ones the grid has none for; the roles are read from `Robust.HG.ini` with the ini reader
- update: `setup.json` and the backups are readable by the group of the install (640, folders 750)
- new: `RemoteGridPlanner` and `Models\Grid::remoteFields()` (joining a grid on another machine); `SetupFile::gridValues()`, `simValues()`, `regionValues()`, `owner()` and the prompts replace the answers by label
- new: `Models\Simulator::fields()`, `Models\Region` (its fields), `SimPlanner` and `RegionPlanner`: from the answers about a simulator and a region to the plan
- new: `OpenSim\Forms\FieldSet` (the standard definition of the fields of a form: conditions, follows, callbacks, groups), `Models\Grid::fields()`, and `Provisioning\GridPlanner`, from the answers about a grid to its plan
- new: `OpenSim\Runtime\DotnetInstaller` installs the .NET runtime and libgdiplus (system packages, an installed runtime, or the script of Microsoft)
- new: `SimulatorMaster`: the simulators of a grid read a generated master (`OpenSimGridDefaults.ini`, `-inimaster`), their own config says what is theirs
- new: `ConfigReader`, `GridConfig`, `ConnectionString`: one reader of the ini files and of the connection strings (`IniReader` is gone, `OpenSim_Config` a thin legacy class)
- new: `OpenSim\Moderation\UserBan` writes the statements that ban users and delete their data
- update: `OpenSim_Oar` is `OpenSim\Archives\Oar`; the old global names are classes of `legacy/`
- update: a grid is its services (its Robust), `simulators: true` adds the simulators that depend on it
- new: `OpenSim\Config` (Layout, IniEditor, IniReader, GridFileService), `OpenSim\Provisioning` (plans, config writers, presets, features, accounts, provisioners), `OpenSim\Archives`, `OpenSim\Network\Ports`
- new: `OpenSim\Testing` has the test doubles of the machine for the front-ends
- new: `OpenSim\OpenSim` starts, stops, restarts and reports on grids and simulators (`Models`, `Runtime`, `Console`)
- new: the namespace `OpenSim\` in `src/` (PSR-4, one class per file): `OpenSim\Network\PortMapping` (the port mapping of the instances: `KickInsideMapper`, UPnP in PHP with `magicoli/kick-inside`, the default; `UpnpcMapper`, the `upnpc` command, the alternative; `PortMappingService`), `OpenSim\Network\InstancePorts`, `OpenSim\Provisioning\Slug`
### 3.0.0-beta.6

Freeze current state before refactor

- update: the functions the helpers had a copy of are only the engine's, the most complete of both: destinations (`opensim_parse_url`, `opensim_uri`), teleport links, region lookups, XML answers and errors, the currency functions, the XML-RPC polyfill
- fix: `is_uuid()` accepts `null` when asked, `osDie()` and `osXmlDie()` keep the message on one line, `make_random_guid()` exists
- update: `opensim_db_for()` finds the database of a service (the consumer's `db_for()` when it has one)
- update: `opensim_get_avatar_session()` (presence of an avatar) and `opensim_db_for()` are in `functions.php`, usable without the economy
- new: `OpenSim_Config`, the config of an OpenSim environment (includes, constants) as a tree that follows the ini files
- new: `OpenSim_Health`, the checks of a grid (what it says, the config, each service with and without data) as data
- fix: `opensim_parse_url()` keeps a zero in a position (`Plaza/0/128/30` lost its first number)
- update: `osDie()` writes its message and logs only a failure of the server (5xx), `osXmlDie()` answers `text/xml`
- tests: never use it('is ugly'), use test()
- refactor(functions): the presence session of an avatar and the database of a service are general functions, not the economy's
- fix(destinations): a zero in a position is a part of it, opensim_parse_url kept only the others
- feat(health): the profile of the config is one row (shown with [AGENT_NAME], linked and asked with the owner), SearchURL of the login service is asked for what it is when it differs from search
- feat(health): search is the web search of the grid info, SearchURL of the login service is compared with it
- feat(health): get_grid_info asks the profile without a name only, the one with an avatar is in the config; the home of the grid is asked at /get_grid_info
- feat(health): grid_info() asks what the grid says as it says it, the web search tag is on both rows of the Robust SearchURL; docs: OpenSim_Config and OpenSim_Health
- feat(config): OpenSim_Config reads an OpenSim environment (includes, constants) into a tree that follows the ini files; health starts with get_grid_info, compares it with the config, calls each service with and without data
- feat(health): checks by file of the config, one row per service, what counts is the answer (a certificate does not change it), nothing said of what is not defined
- docs(readme): the polyfill is the engine's, OpenSim_Health checks a grid
- feat(health): OpenSim_Health, the checks of a grid as data for any consumer; osDie logs only server failures, osXmlDie answers text/xml
- refactor(functions): the functions the helpers copied are the engine's, the most complete of both; tests move here

### 3.0.0-beta.5

- update: `dev/` runs [build-tools](https://github.com/magicoli/build-tools) instead of its own copy of the scripts
- update: [bash-tools](https://github.com/magicoli/bash-tools) 1.0.7 in require-dev, the scripts use its functions

### 3.0.0-beta.4

- new: `dev/release.sh` makes the whole release, `dev/switch.sh` the composer part
- new: `dev/build.sh` makes the Debian package and the zip

### 3.0.0-beta.2

- new: `OpenSim_Oar` packs, reads and checks OpenSimulator archives (plain ustar entries)
- update formatting rules
- update dependencies

### 3.0.0-beta.1

First beta of the 3.0 engine, used by opensim-helpers.

- new: `OPENSIM_CONFIG_DIR` sets the config directory, the engine never creates it
- new `opensim_latin1_only()` is in the engine, for the engine and the helpers
- update: PHP 8.2 minimum, `gettext` required
- update: the REST client is the `magicoli/opensim-rest-php` package, not a copy
- update: unused `opensim_filter_text*` disabled, `laminas/laminas-filter` dropped
- update: code formatted from `.editorconfig` and `.prettierrc.json`
- update tests: a pest suite checks the PHP minimum and compatibility
- fix `opensim_sanitize_uri` accepts uppercase hosts and has explicit nullable types
- fix an empty MySQL port is set to its default, which caused fatal errors
