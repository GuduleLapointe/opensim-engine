## Changelog

### Unreleased

- fix: force latest container-tools composer package

### 3.0.0-beta.12

- chore: bump composer dependencies

### 3.0.0-beta.11

- feature (web): render web server snippets from shipped presets

### 3.0.0-beta.10

- magicoli/build-tools 0.1.10 => 0.1.11

### 3.0.0-beta.9

- feature (setup): collect explicit listener ports before provisioning
- feature (setup): stage an existing region without creating a replacement
- feature (setup): apply prepared settings without repeating the questionnaire
- feature (setup): expose the prepared instance identity
- feature (setup): collect configuration without provisioning
- feature (container): attach interactive exec to terminal
- feature (container): add Podman lifecycle library
- fix (setup): propose portable grid names through the shared generator
- fix (setup): preserve region identity when applying a move
- fix (setup): reject changes to confirmed settings on the target
- fix (api): retain an optional instance on internal errors
- fix (api): advertise setup capabilities before provisioning
- update (dependencies): record container tools integration

### 3.0.0-beta.8

- Upgrading magicoli/bash-tools (1.0.7 => 1.0.8)
- Upgrading magicoli/build-tools (0.1.6 => 0.1.9)
- other composer dependencies updates

### 3.0.0-beta.7

- fix (provisioning): set Robust listener port
- fix (setup): use saved SQL account for new grids
- fix (setup): pass database admin credentials on review
- fix (runtime): accept newer system dotnet packages
- setup: use default values to avoid empty fields
- is it cake?
- feat(package): the engine makes the system layout when it is installed
- feat(setup): the owner of the folders of the layout survives upgrades (dpkg-statoverride)
- feat(setup): the engine makes the system layout (account, folders, opensim.conf) as root
- docs(presets): clearer headers of the web server configs
- refactor(setup): the web report in one sprintf
- feat(setup): the three web server configs are always written, each says in its header what to add
- feat(setup): web server snippets that only take the paths of the helpers, and a short web report
- Revert "feat(start): instances run without IPv6 unless the environment says otherwise"
- feat(setup): the web site note is in the final report, not in the log
- feat(start): instances run without IPv6 unless the environment says otherwise
- Revert "feat(setup): warn about a .local name of the machine"
- feat(setup): warn about a .local name of the machine
- feat(api): an internal error is logged with its detail, the answer still tells none
- feat(start): what a long wait is on takes one line that the next step replaces, with the time a service took
- feat(setup): the steps of a start are told, and a long wait says where it is
- fix(setup): the initialization object of a new region is shipped with the engine
- feat(setup): the notes of the install are told as they come, the wait says it installs
- feat(api): document the directory info, block and asset pages of the helpers
- fix(options): the offline messages of Robust: the services, the module and the forwarding of the groups, as OpenSim wants them
- feat(setup): the first account of a grid, its administrator, gets the level 200
- fix(options): the offline messages are kept by Robust (the module V2) unless the helpers are asked
- refactor(setup): everything the setup writes comes from the presets, the code collects the choices and puts the values in
- refactor(setup): the setup makes a new grid only; Helpers.ini and the web server files are presets
- refactor(options): offline is one option over the module V2, the helpers, bakes and groups settings are unexposed presets
- feat(instances): an address (host:port) names an instance, a grid's Robust or a simulator
- feat(options): every option is a preset (option.conf and the ini files it lays over the grid's), one place, at the setup or later
- fix(names): the nick of a grid keeps the hyphens of its name, the instance of a simulator is grid.simulator
- fix(setup): an account another grid uses keeps its password, the output of the commands stays out of the answer
- feat(setup): the whole setup: databases, account, start, assets and library, Helpers, presets and grids joined from outside
- fix(setup): a grid that exists is a taken name, no MoneyServer for a currency that has none, no ownership message without a web group
- fix(setup): Install checks the place it writes in and the files it wrote; PHP noise stays out of the API answer
- feat(setup): the quick setup is a scenario the engine gives, screen by screen, and Install makes the folders and the files
- feat(setup): the quick setup lists its types and its options, takes ssl, search, gloebit and ossl, and reports what is on
- feat(models): the limits of the viewers: password 16, ASCII hosts, names of 63+63, parcels, regions up to 128, dots in grid names
- refactor(runtime): start, stop and restart give an Outcome and throw InstanceFailed; InstanceResult is gone
- refactor(controllers): AbstractController, ports() by kind of controller, Status holds the controllers
- refactor(avatar): one name rule, Avatar::validateName, for any use (create, pick, import)
- refactor(models): HasName trait gives the same name methods to Region, Grid and Simulator; NameRule class removed
- refactor(runtime): Runtime\Result is InstanceResult, OpenSim\Result keeps the name of the answers
- fix(region): a region name has 50 characters at most, 3 at least for the map search
- refactor(models): no RegionName, the name methods are the models', booleans through Result, regions follow Second Life
- refactor(models): a rule is written once, the dot is out of region names, Uuid::isValid and one port range everywhere
- refactor(models): the rules of names, addresses, email and password are the models', the fields force them
- feat(setup): the hyphen is accepted again in the name of a new avatar
- test(setup): ni hao, ni hao ma
- feat(setup): every name is transliterated from every alphabet and cleaned, the error that remains is told
- feat(setup): no dot, hyphen or underscore in the name of a new avatar, no underscore in a region, domain names in unicode kept
- feat(setup): hostnames in lower case, names without accents (the person validates the change), the region of a standalone checked
- feat(setup): the problems of the quick setup are one or two words
- feat(setup): the quick setup checks the login address and lists every value it defines
- feat(setup): the hypergrid is a question of the quick setup, on by default
- feat(setup): POST /setups/quick, the setup a few answers stand for and what is wrong with them
- refactor(api): read_rc in lib/rc-helpers runs the .env and ~/.opensimrc, no environment forwarding
- feat(api): the scripts read the rc files (opensimrc), the OPENSIM_* variables reach php as arguments
- feat(api): bin/opensim-api, the API on the command line for tools that are not PHP
- feat(standalone): the settings of a grid for a standalone, its features, its questions, its start, stop and monitor; fix the messaging of the groups
- feat(setup): standalone grids made by the setup, no Robust, one simulator, the database of the grid
- feat(security): the API is closed: API keys (made, listed, revoked on the machine), public operations marked open in the contract, a page to try it with a key
- refactor(health): the methods are in camel case
- feat(api): the router without a transport, and the command line and the PHP library as adapters of it, beside the PSR-15 handler
- feat(api): the monitor of a grid (data, the lines of opensim status, a picture for a prim), its addresses and its info as endpoints; the helpers pages group
- docs(api): sections (machine, grid, application, helpers, Robust) with short titles, instead of a prefix on every tag
- test(archive): the tests of the archives are back (a file of the same name was overwritten), the model has its own; the text renderer takes the first font the machine has
- feat(api): archives and backups of the grids as a resource (read only)
- docs(developers): serving the engine from a web host with the handler
- feat(api): the health of a grid as an endpoint; the contract is valid OpenAPI (offline paths without the last slash, 404 on the pages)
- docs(api): every endpoint is in the contract (search, registration, offline messages, currency, land tools, pages, get_grid_info), the catalog says where the engine serves each, the old textgen parameters
- feat(guide): a grid with no list has its popular places and the places shown in search, as sections or as the lines the boards read
- test(destination): the session of an avatar is tested with the currency
- feat(economy): the currency and the land tools of a grid as XML-RPC services (quote, buy, balance, transfers, land), the currency configured by the grid
- feat(setup): offline messages are a choice (helpers with the module V1, Robust with the module V2, or both), enable|disable offline
- feat(offline): the offline messages of the module V1 (save, retrieve, mail), served by the handler
- feat(search): the registration of simulators, the parsers of snapshots and events, and the questions of the viewers (XML-RPC), served by the handler
- feat(api): the message of the day, the destinations of the guide and the images of a text as standard endpoints
- feat(api): the API as a PSR-15 handler, PSR-7 messages and PSR-17 factories given by the application
- style(data): prettier on the cache
- feat(data): two-level Cache (session memory, search database or files), grid info built on it
- refactor(grid): what a grid said when it was verified is kept for a day and read from there; nothing is asked at each call, a failed verification leaves it as it is
- feat(grid): the answers of get_grid_info are kept for a few minutes and never served older, no fallback to another address; the address of a visitor follows the login their grid says
- feat(grid): the login the grid says is its login, whatever address it was reached at: Grid::loginUrl(), uri() and regionUri() follow it
- fix(destination): the port of a login address is the one the address gives, no special case for a grid, none invented
- feat: tri-state Result (isTrue, isFalse, isSuccess, isError), Bitmask, Connections::test, Destination::loginUrl and sanitizePlus, the wants and skills of an avatar: the useful functions of the old OpenSim class
- refactor(naming): a link is a URL, a URI identifies: the addresses of a grid and of a service are urls
- feat(assets): resize on request (a JPEG is converted too then), php-imagick required; Uuid::isNullKey() and isEmpty() for the two keys of nobody; the accessors of the usual addresses of a grid
- feat(assets): Asset for every type, Image for every picture, WebAssets converts and caches them; every address of a grid in one call (Grid::addresses, GET /grids/{grid}/addresses)
- feat(grid): Grid::gridInfo() gives every element Robust says at get_grid_info, the live ones first, else its config; one reader for the engine
- refactor(services): the get_grid_info of Robust is get-grid-info in the catalog, not a name the engine's own pages can take
- feat(api): the resources of a grid, the tasks, the forms and the writes; the web services in the catalog; the documentation made from the contract (composer docs)
- docs(migration): why the folder is still sims
- docs: README, developer rules, migration guide and data guide follow the current classes
- style: code formatted again with Prettier; the TLS health test skips where openssl is LibreSSL
- feat(api): resources as JSON:API documents, errors as RFC 9457 problems, the OpenAPI contract checked against the models
- feat(scheduling): tasks with an interval run by cron or pseudo cron, locked, state kept for the health page
- feat(avatar): Avatar::create() and a fuller update() through the models, each row in the database of its service; AccountImporter on them
- feat(grid): standalone grids (Standalone = true in the description): lifecycle through the simulator, services served by it
- feat(services): Service model; Grid::services()/service()/robusts(), Simulator::services()/service()
- refactor(simulators): sims becomes simulators in the code (GridConfig::SIMULATORS names the folder, tree key, form field)
- refactor(environment): every operation takes an Environment, no profile array; Ports uses the process table; EnvironmentFake for tests
- refactor: the programs that run are controllers (RobustController, OpenSimController), the simulator is a model that composes its controller; Core is a Runtime class
- feat(models): grids, simulators and Robust follow the contract of the models: listed and found in the environment of the machine
- refactor(models): the bans are in the models, Moderation is gone: Avatar finds people and tells what they left, Estate bans
- refactor(slug): a slug is kebab-case, a table or a database is snake_case, the slug of a simulator has its grid
- feat(models): a simulator is identified by its address, its slug has its grid; sqlite tests skip without the extension
- feat(models): Estate, with its regions, managers, users and ban list; a region knows its estate
- fix(data): each query goes to the database of its service, as the core chooses it
- refactor(models): a Robust tells what its config says, the grid and its planner ask it
- refactor(models): a simulator tells what its config says, detail() for Grid and Simulator
- refactor(models): GridInfo goes into Grid, which owns what is known of a grid whatever the source
- refactor: Simulator, never Sim, in the names of classes, methods and variables
- refactor(models): GridState and SimState are gone, Grid and Simulator enable themselves
- refactor(models): a region is one class whatever its source, Enablable replaces RegionState
- refactor: the model interface and the exceptions have their own namespaces (OpenSim\Interfaces, OpenSim\Exceptions)
- refactor: the functions of the engine are classes, loaded by the autoloader; what was removed is back (results, responses, escaping, ids...)
- refactor: OSPDO and the health checks are PSR-4 classes, the legacy aliases are gone
- refactor: the engine drops the classes and functions of the WordPress plugin that nothing here uses
- feat(provisioning): a grid joined by a preset has a master made from its preset, and its features are enabled there, for all its simulators
- feat(provisioning): the options of a new grid (search, currency, more OSSL, web server and site) are fields of the grid, each with its default
- fix(provisioning): each connector Robust serves has its counterpart in the simulators (bakes, groups, estates of the grid); offline messages are left to the helpers
- fix(provisioning): helpers.ini of the first setups becomes Helpers.ini, or is kept aside when both exist
- fix(provisioning): a grid whose files do not say its name is named by the words of its nick
- fix(provisioning): the connectors of the helpers' services and of the hypergrid are enabled in Robust
- fix(provisioning): the scripts of a simulator on the machine of its grid see the grid host, a hypergrid tells its gatekeeper in the grid info
- composer update
- tests: align descriptions to guidelines
- feat(models): Region::all() can be asked for given UUIDs, and Region::known() says which regions the grid has
- feat(models): Avatar::capabilities() says what the list of a grid can sort on and filter by
- fix(models): a search looks for the characters it is given, % and _ are not wildcards
- test: the machine of the tests uses the real path of the temp folder, the ports test does not depend on the ports of the machine
- feat(models): avatars have their whole profile, picks and classifieds, a filter for the account of the services, and keep their email private
- feat(data): the numbers of a grid are counted by the engine, in the database of each service
- feat(models): a list that cannot be read says why, with what the server answered
- refactor(models): roles() is the roles of a region, flagNames() the names of a value, the grid offers its own
- composer update
- docs(data): how an application reads the avatars, regions, parcels and databases of a grid through the engine
- test(models): the tests on SQLite are skipped where pdo_sqlite is not installed
- feat(models): the parcels of a grid, from the land of its simulators, with their bitmap
- feat(models): the regions of a grid, their flags as constants, RegionFlags is gone
- feat(models): the model contract, the collection and the avatars of a grid
- feat(database): the services of the registry are read from Robust, else the reduced copy of a web host, else a standalone OpenSim.ini
- feat(database): the engine defines the databases of a grid and where each is set
- refactor(database): one OSPDO constructor for the DSN and the credentials, the driver options stay PDO's
- feat(backup): the databases are dumped through PDO, not mysqldump
- (untested) feat(database): the setup reaches the database through OSPDO, not the mysql client
- fix(accounts): the home of a new account is above the sea level, not on the sea floor Robust gives
- style: comments say what the code does, its arguments and its return value
- feat(config): the database settings OpenSimulator takes from [DatabaseService] are in the grid config, for the services, the regions and the estates
- feat(signin): the same sign-in with the OpenID provider of the grid, and with the login service of the viewers, which opens a session in the world
- feat(signin): an avatar signs in through the accounts and authentication services of the private port of its Robust, no database, no session in the world
- feat(i18n): gettext is suggested, not required; without it its functions give the message back as it is
- feat(config): the layouts of an install are the engine's, and Layout::resolve() reads the environment, then opensim.conf, then the defaults of the setup
- composer update
- fix(regions): a new region offers every role and checks only the ones the grid has no region for; the roles are read from the Robust config with the ini reader
- fix(database): a plan takes the password entered for its account earlier in the session; what the setup is about to create is a note, not a warning
- feat(runtime): ShellService gives the output of the commands to a sink when the terminal is not where it goes
- docs(changelog): setup.json and the backups are readable by the group of the install
- fix(files): setup.json and the backups are readable by the group of the install (640, folders 750)
- fix(setup): a region added by a setup file to a simulator that is not to start is not loaded
- feat(forms): RemoteGridPlanner, and the setup file gives its answers by the names of the fields
- feat(forms): RegionPlanner takes the ports of the simulators of a pool, uuid() is public
- feat(forms): the fields of a simulator and of a region are defined by their models, SimPlanner and RegionPlanner work the plans out
- feat(forms): the fields of a grid are defined by the model (FieldSet), the GridPlanner works the plan out from the answers
- docs(changelog): DotnetInstaller
- feat(runtime): DotnetInstaller installs the .NET runtime and libgdiplus, the PHP of libexec/install-dotnet
- fix(master): the decisions of a new grid keep their quotes, the master takes no port of a simulator
- docs(changelog): the master of the simulators, ConfigReader, UserBan, legacy classes
- feat(config): the simulators of a grid read a master (OpenSimGridDefaults.ini, -inimaster), their own config says only what is theirs
- refactor(legacy): the old global names are classes of legacy/, no custom autoloader
- refactor(legacy): the old global names are loaded from legacy/, OpenSim_Oar is OpenSim\Archives\Oar
- feat(moderation): UserBan writes the statements that ban users and delete their data; ConnectionString is the one reader of connection strings
- refactor(config): one reader of the ini files (ConfigReader), OpenSim_Config is a thin legacy layer on GridConfig
- fix(runtime): a link to a library that was removed is not a library
- fix(provisioning): the init archive and the folders of the application are found through the resource registry
- fix(provisioning): the setup can start instances through the application's command line (InstanceControl::runThrough)
- docs: changelog
- fix(provisioning): the setup starts and restarts instances in its own process (InstanceControl), not through a path of the command line
- tests: the tests of the classes that came from the kit run in the engine; it needs the random name generator
- refactor(provisioning): the writing of a grid and of a simulator (GridProvisioner, SimulatorProvisioner) and the setup file are the engine's; the screens stay in the kit
- fix(provisioning): the resources of the application are found from composer's root package, without its bootstrap
- feat(models): Core::choices, the cores under a root as the choices of a menu
- fix(runtime): the processes are listed without cutting their command line, console applications set COLUMNS and ps cuts the lines to it
- refactor(provisioning): the database, the accounts, the registry of a grid, the archives, the import, the ports and the console of an instance are the engine's
- refactor(provisioning): the classes that make and read a grid come from the kit into OpenSim\Config, \Provisioning and \Models (config files, plans, presets, features, MoneyServer, helpers, locations, grid state)
- refactor(runtime): a grid is its services, its simulators start, stop and restart with it only when asked
- refactor(config): IniEditor and TextFileService, the line editor of ini files and the Unix line endings, come from the kit
- feat(runtime): OpenSim::start/stop/restart/status over Grid, Robust and Simulator models, with their runtime, consoles and tests
- feat(network): the namespace OpenSim\ in src/, the port mapping of the instances with kick-inside by default and upnpc as the alternative, the ports of an instance

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
