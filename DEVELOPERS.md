# OpenSim Engine Development Rules

The engine is a pure library: it reads and writes the data of OpenSimulator grids (files, databases, consoles, processes) and nothing else. It never accepts HTTP input nor gives HTTP output, it never reads a global: a front-end (the helpers, the manager, a command, a plugin) gives it what it needs and shows what it returns.

- **Nothing of the projects that use it** (no `w4os`, `wordpress`, `helpers` in a name), no framework.
- **The engine is the only reader of the databases, the files and the consoles of a grid.** The consumers ask it; they never query a table or parse an ini themselves.
- **Environment, not globals.** Whatever acts on a machine takes an `OpenSim\Runtime\Environment` (its layout, processes, launcher, consoles, clock). Whatever reads a grid's databases takes a `GridConfig`. No `Layout::load()` in the middle of a class.
- **Names.** PSR-4, `OpenSim\` is `src/`; a class goes with its domain thing (`Avatar`, `Estate`, `Parcel`, `Region`, `Service`), not in a folder of its feature. Say `Simulator`, never `Sim` (except what OpenSimulator itself names so: `index_sims`, the console commands). A slug is kebab-case (`green-field`), a table or database name snake_case, the nick of a grid is snake_case with the hyphens of its name kept (`mary-jane_world`), the instance of a simulator is its grid, a dot, its own name (`mary-jane_world.welcome_area`), its id is its URI (`host:port`).
- **Every model offers the same** (`ModelInterface`: `id() name() slug() uri() detail() channels() update() delete()`, `all()` and `find()`), whatever it comes from. A write goes through a channel and is refused with `ChannelUnavailable` when none can do it.
- **Every database is asked as itself**: the `ConnectionString` of its service, else `[DatabaseService]` (`OpenSim\Data\Database`). Never assume the services share one.
- **Never delete logic because nothing calls it**; port it, tag it, say what was dropped and why.
- **Errors are explicit**: an exception that says what failed (`ChannelUnavailable`, `InvalidArgumentException`), told as a problem (RFC 9457) by `OpenSim\Api\Problem`. No intentional 500.
- **Comments say what, not why or how**: the docblocks tell what a thing is, its arguments, its result. The history is in git.

## Tests

The suite needs nothing of the machine it runs on: `composer test` (Pest). The fakes of `OpenSim\Testing` stand for the install, the processes, the consoles and the clock; the databases are SQLite in memory with the tables of OpenSimulator. A test that needs more (podman, a real database) is skipped, with the reason, where it cannot run.

`vendor/bin/pest --filter=ServiceTest` runs some tests. After a class is added, `composer dump-autoload` (the class map is authoritative). The packaging test (`PACKAGING=1`) builds the packages in a container.

## The API and its documentation

The resources are the models'; `Api` names them and writes the documents, `Contract` is the OpenAPI document and the text of the documentation (the descriptions of the attributes are written there). `composer docs` makes `docs/api/` (JSON, YAML and a page, Redoc; `npx @redocly/cli lint docs/api/openapi.json` checks that the document is valid OpenAPI, it is not a dependency of the project: the one warning left is the media path, `/assets/{id}` and `/assets/{id}.{format}`); the tests fail when the pages are not the ones of the contract, or when a model gives an attribute the contract does not know. A new resource is a model, an entry of `Contract::RESOURCES` and of `Api::RESOURCES` or `Api::GRID_RESOURCES`.

A web host (the helpers) serves the engine like this:

```php
$factory = new Nyholm\Psr7\Psr17Factory();                 // any PSR-7 / PSR-17 implementation
$environment = OpenSim\Runtime\Environment::system(OpenSim\Config\Layout::load());
$scheduler = OpenSim\Scheduling\Scheduler::for($environment);
OpenSim\Services\SearchTasks::add($scheduler, $environment, $config);   // the tasks of the search of the grid
$handler = new OpenSim\Api\Handler(
    new OpenSim\Api\Api($environment, $scheduler),
    $factory,
    $factory,
    base: '/helpers',
    grid: 'nick',
    authenticator: OpenSim\Security\ApiKeys::for($environment),   // closed without it
);

$response = $handler->handle($request);                    // emit it, then:
$handler->terminate();                                     // the simulator that registered is read, the tasks that are due run
```

The API is closed: what manages the machine and the grids needs a key (`Authorization: Bearer <key>`), made on the machine with `opensim api-key create LABEL` (`list`, `revoke`), kept hashed in `api-keys.json` of the state folder (mode 640, the group of the web server reads it). What the viewers, the simulators and the visitors call is open (`Router::PUBLIC`, marked `security: []` in the contract; a test checks that the contract and the router agree), and the calls in a format of OpenSimulator and the viewers are protected by what their protocol has. The command line and the PHP library are trusted (the system protects the files): no key. `docs/api/try.html` is the page to try the API (Swagger UI: the address, Authorize for the key).

The addresses are in the contract (`docs/api/`), with the format OpenSimulator and the viewers expect for the ones they fix. A new fixed-format endpoint is described in `Api\Formats` (its methods and fields, with an example each), a test checks that what is described is what the service answers.

`Api\Router` holds the addresses of the API without a transport (`route($method, $path, $query, $body)`, and `get()`, `post()`, `patch()`, `delete()` for a program that calls it as a library); its adapters are thin: `Api\Handler` for a web host (PSR-15), `Api\Cli` for the command line (`opensim api GET /grids/demo/monitor`). `Api\Handler` is the API as a PSR-15 handler (`new Handler($api, $responseFactory, $streamFactory, base: '/api')`): the engine requires the PSR interfaces only, the application brings the implementation of the messages (the tests use nyholm/psr7). Its addresses are the ones of the contract, which it serves at `/openapi.json`. A host that serves one grid gives it as `grid:` and its endpoints lose the `/grids/{grid}` (`/motd`, `/destinations`, `/avatars`): these are the standard addresses of the services the catalog calls `standard`.

## The options of a grid

An option (search, economy, offline messages, extended OSSL, whatever an administrator invents) is a preset, and so is what the grid has by default without being asked (the profiles, the addresses the viewers are told of the helpers, the bakes, the groups, the offline messages of Robust): the same folders, with `Offer = false` so that the setup does not list them: a folder with an `option.conf` and the ini files it changes, in `<etc>/presets/<name>/` (the administrator's, which wins) or `share/presets/<name>/` (the ones of the kit). No option is code: `OpenSim\Provisioning\GridOptions` lays each ini of the folder over the file of the grid that has the same name, at the setup and later (`opensim enable|disable <grid> <option>`):

- `OpenSim.ini` goes to the simulators (the OpenSim.ini of the grid, and the files of its simulators), `Robust.ini` to the services (Robust, or StandaloneCommon.ini for a standalone), any other path (`config-include/osslEnable.ini`) to the same file of the grid;
- a setting is written as OpenSimulator reads it; `{{grid_name}}`, `{{search_url}}`... are put in by the grid (`GridOptions::variables()`), a setting with nothing to put in is not written; a setting without a value (`OfflineIMServiceConnector =`) switches on, as it is, the one the file has commented out;
- a file the grid does not have is copied from the preset with its variables put in, whatever it is (`Helpers.ini`, `web/{{grid_nick}}.caddyfile` of the preset `webserver-caddy`): to change the file a grid gets, change the preset; a file the grid has is kept (an ini is merged); the presets give values to each other in a `[Variables]` section of their `option.conf` (`docroot`);
- disabling comments the settings out, with their values;
- `option.conf` says what the option is and needs: label, offered in the setup or not, on by default, group of alternatives (`economy-beta`, `economy-gloebit`), what the grid must be or have, the modules of the install, the settings the administrator gives (the Gloebit keys), the settings written only once or kept when disabled, and the one of a group that is on when no other is (`Fallback = true`: the module V2 of the offline messages, which the helpers replace while they are on). The keys are listed in `OptionTemplate`.

The options a grid has are kept in its description (`Options = search, profiles`).

Everything the setup writes comes from the presets; the code collects the choices (a plan: `GridPlan::variables()`, `SimulatorPlan::variables()`, each property by its name in snake_case, `baseHostname` is `{{base_hostname}}`) and puts the values in the files:

- the grid: `grid` (its description `<nick>.conf`), `robust` (its config), `identity`, `hypergrid`, `estates`, `bakes`, `groups-*`, `robust-log` (the log4net config of Robust), `profiles`, `helpers`, `search`..., `Order` says which is laid first (`0` the description, `10` Robust, `20` the names of the grid; `50` by default);
- what the folders copied from the core need (`config-include`: the includes pointing to the copies, the storage of the simulator, the assets and the library of the grid), the master of the simulators (`master`), a simulator and its region (`simulator`, `region`) and a grid that runs elsewhere (`remote-grid`) are presets that the code takes by name: `Default = false` keeps them out of the options;
- `Show = false` keeps the plumbing out of the report of the setup;
- in a file, `;; @if standalone, !hypergrid` ... `;; @end` keeps the lines between for the grids that are so (the words of `Requires`, or a variable that has something to say); a setting with the value `{{off}}` is commented out.

## Formatting

Prettier with the PHP plugin (`.prettierrc.json`, `.editorconfig`), no PHP binary needed. The plugin has a known bug: it drops the parentheses of `($a ? $b : $c) ?: $d`, which then does not compile; write it in two statements. `php -l` every file it changed.

## Build

`dev/build.sh` makes what the project distributes into `dist/`, from the committed tree (commit first: the version carries the hash of HEAD, and `.dirty` when files are changed):

- a Debian package, `opensim-engine_<version>_all.deb`, in `/usr/share/opensim-engine`: its `vendor` folder has the third-party libraries, and `opensim-rest-php` is a link to the package of that name, which it depends on;
- a zip, `opensim-engine-<version>.zip`: the files with a complete `vendor` folder (opensim-rest-php included, as files), to unzip and use without composer.

`dev/build.sh deb` or `dev/build.sh zip` makes one. What is distributed is what git tracks (so what `.gitignore` ignores is not there) without what `.distignore` lists, plus the `vendor` folder composer makes without the development tools, from the repositories of `composer.json` (a path repository in development, else Packagist). The work is done on copies, the `vendor` folder of the project is not touched. The scripts are those of [build-tools](https://github.com/magicoli/build-tools) (`vendor/bin/build-tools`, a development dependency); the project keeps its own `packaging/`: the nfpm definition `opensim-engine.yaml`, `build` (the files of the package), `siblings` (the projects of the family the Debian package gets from their own packages), `opensim-engine.preinst`. It needs nfpm and composer.

`tests/Packaging/check` tries both in a clean container (podman) with the Debian package of opensim-rest-php (`DEPS=folder` to say where it is, default `../opensim-rest-php/dist`), `PACKAGING=1 vendor/bin/pest` runs it after the build where podman is available. What the container needs is sent to it as a tar stream: `CONTAINER_CONNECTION=name` (a `podman system connection`) runs it on the podman of another machine, `MEMORY=` sets its memory (default 400m); both can be set in `tests/.env` (see `tests/.env.example`), read after the `.env` of the project (bash-tools `read_env`: what the files set wins over the environment of the command). `TEST_DATABASE` (a PDO dsn, `sqlite::memory:` in the example) is the database of the tests that need one, through `test_pdo()` of `tests/Pest.php`; they are skipped without it.

## Release

One command in each project, in the order of the family (rest-php, engine, helpers, kit), each one completely before the next:

```bash
dev/release.sh          # the whole release, after one question
dev/release.sh status   # what is done and what remains
dev/release.sh beta     # patch|minor|major|stable|dev|alpha|beta|rc|1.2.3-beta.4: see the versions in the README of build-tools
```

It needs the `.env` of the project to say where the apt repository is (`APT_REPO_DIR`, see `.env.example` of build-tools), `gh` (logged in) and `nfpm`, and says what is missing before it does anything. `dev/switch.sh dev|release` does the composer part alone: the projects of the family linked next to this one (path repositories, `@dev`), or required by version (`^` the latest version tag of each, refused when a project changed since that release).

## Shell scripts

The scripts of `dev/` are wrappers of build-tools. `tests/Packaging/check` uses the functions of [bash-tools](https://github.com/magicoli/bash-tools) to ask, tell and fail (`log`, `success`, `warning`, `die`, `end`, `yesno`, `require`, `usage`, `read_env`), loaded from `vendor/bin/bash-helpers`: write the new ones the same way, not with their own prompts and `echo`.
