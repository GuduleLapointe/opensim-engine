# OpenSim Engine Development Rules

Although this library is primarily designed to be used with W4OS WordPress plugin and the OpenSim Helpers project, it can also be used independently. Therefore, it is important to follow the rules below to ensure that the code remains generic and does not depend on WordPress or the Helpers library.

**The Engine is a pure library responsible for data manipulation and storage only.**
It should never accept HTTP input nor provide HTTP output. All requests must be made by calling internal methods and functions, and all responses must be returned by those methods and functions. It is not responsible for user interactions.

When working on files in the `engine/` directory:

- **never use code or concepts related to projects consuming this library**, it must be generic and work with any project.
- Use only generic PHP - no WordPress functions, no Helpers functions
- No `w4os`, `wordpress` or `helpers` references in variable names or constants
- Class names: `Engine_*`, `OpenSim_*`
- Settings use only `Engine_Settings` class
- Pass data as parameters instead of accessing globals directly
- All methods should work standalone without the need of usual parents like w4os plugin or Helpers

## Example Patterns

```php
// Good - Generic
Engine_Settings::get('database_host')
$wizard_data = $_SESSION['opensim_engine']['received_data']

// Good - Pass data explicitly
function process_avatar($avatar_data, $db_config) {
    // Work with provided data
}

// Bad - WordPress specific
get_option('w4os_database_host')
$wp_data = $_SESSION['w4os_wizard_data']

// Bad - Hidden dependencies
function process_avatar() {
    global $wpdb;
    $config = get_option('w4os_config');
    // Accessing globals makes code unpredictable
}

// Bad - Direct HTTP handling (Engine should NOT do this)
function handle_avatar_request() {
    $avatar_data = $_POST['avatar'];
    echo json_encode($result);
    // Engine should never handle HTTP directly
}

// Good - Pure data processing (Engine SHOULD do this)
function process_avatar_data($avatar_data) {
    // Process and return data only
    return $processed_data;
}
```

## Engine vs Helpers Responsibility

- **Engine:** Data processing, database operations, OpenSim protocol
- **Helpers:** HTTP handling, form processing, HTML output, user interface

## Build

`dev/build.sh` makes what the project distributes into `dist/`, from the committed tree (commit first: the version carries the hash of HEAD, and `.dirty` when files are changed):

- a Debian package, `opensim-engine_<version>_all.deb`, in `/usr/share/opensim-engine`: its `vendor` folder has the third-party libraries, and `opensim-rest-php` is a link to the package of that name, which it depends on;
- a zip, `opensim-engine-<version>.zip`: the files with a complete `vendor` folder (opensim-rest-php included, as files), to unzip and use without composer.

`dev/build.sh deb` or `dev/build.sh zip` makes one. What is distributed is what git tracks (so what `.gitignore` ignores is not there) without what `.distignore` lists, plus the `vendor` folder composer makes without the development tools, from the repositories of `composer.json` (a path repository in development, else Packagist). The work is done on copies, the `vendor` folder of the project is not touched. The scripts are in `packaging/`: `version`, `stage` (the files and the vendor folder), `build`, `zip`, `siblings` (the projects of the family the Debian package gets from their own packages), and the nfpm definition `opensim-engine.yaml`. It needs nfpm and composer.

`tests/Packaging/check` tries both in a clean container (podman) with the Debian package of opensim-rest-php (`DEPS=folder` to say where it is, default `../opensim-rest-php/dist`), `PACKAGING=1 vendor/bin/pest` runs it after the build where podman is available. What the container needs is sent to it as a tar stream: `CONTAINER_CONNECTION=name` (a `podman system connection`) runs it on the podman of another machine, `MEMORY=` sets its memory (default 400m); both can be set in `tests/.env` (see `tests/.env.example`), the environment of the command wins.

## Release

The projects of the family are released in the order of their dependencies, rest-php, engine, helpers, kit, each one completely before the next: the next one requires the version just published (and its tag has to be pushed, Packagist to know it).

```bash
dev/release.sh prepare [VERSION]   # the release commit: .version, the family required by version, CHANGELOG
dev/release.sh publish             # tag, push to the github remote (RELEASE_REMOTE=name), clean build of the tag
```

When the four are published, in the same order:

```bash
dev/release.sh next [VERSION]      # the next development version, the family linked again, a new Unreleased section
```

`dev/switch.sh dev|release` does the composer part alone, and updates `composer.lock`: `dev` links the projects next to this one (path repositories, `@dev`), `release` requires `^` the `.version` of each from Packagist, and waits for Packagist to know it (`SWITCH_WAIT` seconds, 180 by default). It refuses a project that is still at a `-dev` version. A step that fails leaves the files as they were. `publish` tags and pushes: it is yours to run.
