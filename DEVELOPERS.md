# OpenSim Engine Development Rules

The engine is a pure library: it reads and writes the data of OpenSimulator grids (files, databases, consoles, processes) and nothing else. It never accepts HTTP input nor gives HTTP output, it never reads a global: a front-end (the helpers, the manager, a command, a plugin) gives it what it needs and shows what it returns.

- **Nothing of the projects that use it** (no `w4os`, `wordpress`, `helpers` in a name), no framework.
- **The engine is the only reader of the databases, the files and the consoles of a grid.** The consumers ask it; they never query a table or parse an ini themselves.
- **Environment, not globals.** Whatever acts on a machine takes an `OpenSim\Runtime\Environment` (its layout, processes, launcher, consoles, clock). Whatever reads a grid's databases takes a `GridConfig`. No `Layout::load()` in the middle of a class.
- **Names.** PSR-4, `OpenSim\` is `src/`; a class goes with its domain thing (`Avatar`, `Estate`, `Parcel`, `Region`, `Service`), not in a folder of its feature. Say `Simulator`, never `Sim` (except what OpenSimulator itself names so: `index_sims`, the console commands). A slug is kebab-case (`green-field`), a table or database name snake_case, the slug of a simulator is its grid, an underscore, its own slug (`vonda_welcome`), its id is its URI (`host:port`).
- **Every model offers the same** (`ModelInterface`: `id() name() slug() uri() detail() channels() update() delete()`, `all()` and `find()`), whatever it comes from. A write goes through a channel and is refused with `ChannelUnavailable` when none can do it.
- **Every database is asked as itself**: the `ConnectionString` of its service, else `[DatabaseService]` (`OpenSim\Data\Database`). Never assume the services share one.
- **Never delete logic because nothing calls it**; port it, tag it, say what was dropped and why.
- **Errors are explicit**: an exception that says what failed (`ChannelUnavailable`, `InvalidArgumentException`), told as a problem (RFC 9457) by `OpenSim\Api\Problem`. No intentional 500.
- **Comments say what, not why or how**: the docblocks tell what a thing is, its arguments, its result. The history is in git.

## Tests

The suite needs nothing of the machine it runs on: `composer test` (Pest). The fakes of `OpenSim\Testing` stand for the install, the processes, the consoles and the clock; the databases are SQLite in memory with the tables of OpenSimulator. A test that needs more (podman, a real database) is skipped, with the reason, where it cannot run.

`vendor/bin/pest --filter=ServiceTest` runs some tests. After a class is added, `composer dump-autoload` (the class map is authoritative). The packaging test (`PACKAGING=1`) builds the packages in a container.

## The API and its documentation

The resources are the models'; `Api` names them and writes the documents, `Contract` is the OpenAPI document and the text of the documentation (the descriptions of the attributes are written there). `composer docs` makes `docs/api/` (JSON, YAML and a page, Redoc); the tests fail when the pages are not the ones of the contract, or when a model gives an attribute the contract does not know. A new resource is a model, an entry of `Contract::RESOURCES` and of `Api::RESOURCES` or `Api::GRID_RESOURCES`.

## Formatting

Prettier with the PHP plugin (`.prettierrc.json`, `.editorconfig`), no PHP binary needed. The plugin has a known bug: it drops the parentheses of `($a ? $b : $c) ?: $d`, which then does not compile; write it in two statements. `php -l` every file it changed.

## Build

`dev/build.sh` makes what the project distributes into `dist/`, from the committed tree (commit first: the version carries the hash of HEAD, and `.dirty` when files are changed):

- a Debian package, `opensim-engine_<version>_all.deb`, in `/usr/share/opensim-engine`: its `vendor` folder has the third-party libraries, and `opensim-rest-php` is a link to the package of that name, which it depends on;
- a zip, `opensim-engine-<version>.zip`: the files with a complete `vendor` folder (opensim-rest-php included, as files), to unzip and use without composer.

`dev/build.sh deb` or `dev/build.sh zip` makes one. What is distributed is what git tracks (so what `.gitignore` ignores is not there) without what `.distignore` lists, plus the `vendor` folder composer makes without the development tools, from the repositories of `composer.json` (a path repository in development, else Packagist). The work is done on copies, the `vendor` folder of the project is not touched. The scripts are those of [build-tools](https://github.com/magicoli/build-tools) (`vendor/bin/build-tools`, a development dependency); the project keeps its own `packaging/`: the nfpm definition `opensim-engine.yaml`, `build` (the files of the package), `siblings` (the projects of the family the Debian package gets from their own packages), `opensim-engine.preinst`. It needs nfpm and composer.

`tests/Packaging/check` tries both in a clean container (podman) with the Debian package of opensim-rest-php (`DEPS=folder` to say where it is, default `../opensim-rest-php/dist`), `PACKAGING=1 vendor/bin/pest` runs it after the build where podman is available. What the container needs is sent to it as a tar stream: `CONTAINER_CONNECTION=name` (a `podman system connection`) runs it on the podman of another machine, `MEMORY=` sets its memory (default 400m); both can be set in `tests/.env` (see `tests/.env.example`), read after the `.env` of the project (bash-tools `read_env`: what the files set wins over the environment of the command).

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
