# Migration of the consumers

What the engine had as global functions and classes, where it is now. The classes load through the autoloader of composer, nothing is
required by path. Method names that are snake_case keep their shape in the classes that were only moved (`OpenSim\Health\Health`).

## Classes

| Before | Now |
| --- | --- |
| `OSPDO` | `OpenSim\Data\OSPDO` |
| `OpenSim_Health` | `OpenSim\Health\Health` |
| `OpenSim_Config` (`from_folder`, `read_ini`, `read`) | `OpenSim\Config\GridConfig::fromFolder()`, `OpenSim\Config\ConfigReader::expand(ConfigReader::file($path))`, `ConfigReader::load()` |
| `OpenSim_Oar` | `OpenSim\Archives\Oar` |
| `bootstrap.php`, `Engine_Settings`, `OpenSim_*` of the plugin | gone (see NOT-PORTED.md) |

## Models and controllers

| Before | Now |
| --- | --- |
| `Models\Instance` | `Controllers\Controller` (abstract) |
| `Models\Robust` | `Controllers\RobustController`; `Grid::robust()` gives it, `Grid::robusts()` the list |
| the process part of `Models\Simulator` | `Controllers\OpenSimController`; `Simulator::controller()` |
| `Models\Core` | `Runtime\Core` |
| `GridInfo` | `Models\Grid` (one class for a grid, whatever it comes from: folder, plan, description, announcement) |
| `GridState`, `SimState`, `RegionState` | `isEnabled()`, `enable()`, `disable()` of the models (`Interfaces\Enablable`) |
| `Grid::all()` as an array | a `Collection` (`->items()`) |
| the services of a grid, as connectors | `Models\Service`: `Grid::services()`, `Grid::service($key)`, `Simulator::service($key)` |
| a grid without Robust | a standalone: `Standalone = true` in `<nick>.conf`, `Grid::isStandalone()` |

## The environment

The profile arrays (`['EtcRoot' => ..., 'DataRoot' => ...]`) are gone: whatever acts on a machine takes an `Environment`.

| Before | Now |
| --- | --- |
| `Environment::forProfile($profile)` | `Environment::system(new Layout($profile))` |
| `GridPlanner::forMachine($profile, $nick)` | `GridPlanner::forMachine($environment, $nick)`; `defaultPublicPort($environment)` |
| `SimulatorPlanner::forGrid($grid, $profile, $db, $ui, ...)` | `SimulatorPlanner::forGrid($grid, $db, $ui, ...)` (the environment is the grid's) |
| `RemoteGridPlanner::forMachine($profile)` | `RemoteGridPlanner::forMachine($environment)` |
| `GridProvisioner::apply($plan, $profile)`, `SimulatorProvisioner::apply(...)` | `apply($plan, $environment)` |
| `new Features($grid, $profile)` | `new Features($grid)` |
| `PendingRestarts::add|read|clear|path($profile, ...)` | the same with an `Environment` |
| `GridRegistry::knownPorts($profile, $grid)` | `knownPorts($environment, $grid)` |
| `ArchiveRunner::system($profile)`, `new BackupService($profile, ...)`, `Instances::locate|resolve($profile, ...)` | an `Environment` in place of the profile |
| `RobustImporter::plan($raw, $path, $profile, ...)` | `plan($raw, $path, $environment, ...)`; `SimulatorImporter::plan` has no profile |
| `Grid::described($values, $environment = null)` | `Grid::described($environment, $values)` |
| `Ports::next()`, `nextBlock()`, `nextFree()`, `nextRegion()`, `isFree()` (static, read the machine) | `(new Ports($environment))->next()`...; `simulatorsFrom()` and `simulatorBlock()` stay static |
| `Ports::parseBound()` | `Runtime\SystemProcessTable::parseBound()`; the sockets are `ProcessTableInterface::listening()` |
| `GridFileService::read()` elevates as the system user of the layout | it elevates as the owner of the file |

`OpenSim\Testing\EnvironmentFake::with($layout)` is the environment of a test.

## Simulators, not sims

| Before | Now |
| --- | --- |
| the role `sims/<name>` and the key `sims` of `GridConfig::tree()` | `simulators/<name>`, `simulators` |
| the folder `sims` written in paths | `GridConfig::SIMULATORS`, `GridConfig::simulatorsDirectory($gridDirectory)` (the folder on disk is still `sims`, as in the first packages of opensim-debian) |
| the `sim` field of the region form | `simulator` |

## Accounts

| Before | Now |
| --- | --- |
| `AccountImporter::forDatabase($databaseService)` | `AccountImporter::forModels()` |
| accounts written as one stream of SQL | `Avatar::create($config, $values)`: each row in the database of its service; `AccountWriter::tables()` |
| `Avatar::update()` of 4 values | also `password`, `home` and the profile it has |

## New

`Scheduling\Scheduler` and `Task` (cron and pseudo cron), `Api\Api`, `Contract`, `Problem` (JSON:API, OpenAPI, RFC 9457).

`Health::grid_info()` and `Grid::announcement()` are `Network\GridInfoReader::read()`; a grid gives its own with `Grid::gridInfo()`.

The web services of a grid (search, currency, land purchase, registration, offline messages, message of the day, guide, images of a text) are in the catalog of `Models\Service`: where the config of the grid puts each, whether its format is fixed by OpenSimulator and the viewers (`query`, `register`, `offline`, `currency`, `landtool`: only the address is free) or the engine's (`standard`: the old script is its `legacy` name), and `endpoint`, where the engine serves it.

## The scripts of the helpers

Each script is an endpoint of `Api\Handler`, in the contract (`docs/api/`). The helpers keep the addresses the grid gave, and call the engine:

| Script | Endpoint of the engine | Format |
| --- | --- | --- |
| `query.php` | `POST /grids/{grid}/search/query?gk=` | XML-RPC of OpenSimSearch (fixed) |
| `register.php` | `GET /grids/{grid}/search/register?host=&port=&service=` | fixed |
| `parser.php`, `eventsparser.php` | `POST /tasks/search-parse/runs`, `/tasks/events-parse/runs` (or the tasks, run by the scheduler) | JSON:API |
| `offline.php` | `POST /grids/{grid}/offline-messages/SaveMessage/` and `RetrieveMessages/` | XML of the module V1 (fixed) |
| `currency.php`, `landtool.php` | `POST /grids/{grid}/currency.php`, `landtool.php` | XML-RPC of the viewers (fixed) |
| `motd.php` | `GET /grids/{grid}/motd` | plain text |
| `guide.php` | `GET /grids/{grid}/destinations` (resources, or the lines of the boards with `format=list`) | JSON:API |
| `textgen.php` | `GET /text-images`, `Api\Legacy::textgen()` for the old parameters | PNG |

On a host that serves one grid, `new Handler($api, $factory, $factory, grid: 'nick')` drops the `/grids/{grid}`. The application calls `$handler->terminate()` once the response is sent.

## Functions

| Before | Now |
| --- | --- |
| `opensim_parse_url()` | `OpenSim\Hypergrid\Destination::parse()` |
| `opensim_uri()` | `OpenSim\Hypergrid\Destination::uri()` |
| `opensim_sanitize_uri()` | `OpenSim\Hypergrid\Destination::sanitize()` |
| `opensim_format_tp()` | `OpenSim\Hypergrid\Destination::format()` |
| `opensim_region_url()` | `OpenSim\Hypergrid\Destination::regionUrl()` |
| `oxXmlRequest()` | `OpenSim\Hypergrid\Gatekeeper::request()` |
| `get_xml_response_data()` | `OpenSim\Hypergrid\Gatekeeper::post()` |
| `opensim_link_region()` | `OpenSim\Hypergrid\Gatekeeper::linkRegion()` |
| `opensim_get_region()` | `OpenSim\Hypergrid\Gatekeeper::region()` |
| `opensim_region_is_online()` | `OpenSim\Hypergrid\Gatekeeper::regionIsOnline()` |
| `service_available()` | `OpenSim\Network\Service::available()` |
| `isSuccess()` | `OpenSim\Result::isSuccess()` |
| `isError()` | `OpenSim\Result::isError()` |
| `osResponseArray()` | `OpenSim\Result::build()` |
| `osSuccess()` | `OpenSim\Result::success()` |
| `osError()` | `OpenSim\Result::error()` |
| `osDie()` | `OpenSim\Http\Respond::end()` |
| `osXmlResponse()` | `OpenSim\Http\Respond::xml()` |
| `osXmlDie()` | `OpenSim\Http\Respond::xmlEnd()` |
| `die_knomes()` | `OpenSim\Http\Respond::knomes()` |
| `dontWait()` | `OpenSim\Http\Respond::flush()` |
| `set_helpers_locale()` | `OpenSim\Http\Locale::negotiate()` |
| `get_writable_tmp_dir()` | `OpenSim\Support\Tmp::dir()` |
| `php_has()` | `OpenSim\Support\Platform::has()` |
| `opensim_latin1_only()` | `OpenSim\Text\Encoding::latin1Only()` |
| `fix_utf_encoding()` | `OpenSim\Text\Encoding::utf8()` |
| `sprintf_safe()` | `OpenSim\Text\Format::sprintfSafe()` |
| `is_uuid()` | `OpenSim\Text\Uuid::isValid()` |
| `sanitize_id()` | `OpenSim\Text\Identifier::id()` |
| `sanitize_version()` | `OpenSim\Text\Identifier::version()` |
| `sanitize_url()` | `OpenSim\Security\Sanitize::url()` |
| `opensim_filter_email()` | `OpenSim\Security\Sanitize::email()` |
| `opensim_filter_url()` | `OpenSim\Security\Sanitize::filterUrl()` |
| `opensim_filter_key()` | `OpenSim\Security\Sanitize::key()` |
| `opensim_filter_int()` | `OpenSim\Security\Sanitize::int()` |
| `opensim_filter_float()` | `OpenSim\Security\Sanitize::float()` |
| `opensim_sanitize_html()` | `OpenSim\Security\Sanitize::html()` |
| `opensim_sanitize_rich_text()` | `OpenSim\Security\Sanitize::richText()` |
| `opensim_sanitize_basic_html()` | `OpenSim\Security\Sanitize::basicHtml()` |
| `opensim_get_escaper()` | `OpenSim\Security\Escape::escaper()` |
| `opensim_esc_html()` | `OpenSim\Security\Escape::html()` |
| `opensim_esc_attr()` | `OpenSim\Security\Escape::attr()` |
| `opensim_esc_url()` | `OpenSim\Security\Escape::url()` |
| `opensim_esc_js()` | `OpenSim\Security\Escape::js()` |
| `opensim_esc_css()` | `OpenSim\Security\Escape::css()` |
| `caller_details()` | `OpenSim\Diagnostics\Caller::details()` |
| `osAdminNotice()` | `OpenSim\Diagnostics\Caller::notify()` |
| `osNotice()` | `OpenSim\Diagnostics\Caller::notice()` |
| `osdebug()` | `OpenSim\Diagnostics\Caller::debug()` |
| `parse_ini_file_decode()` | `OpenSim\Config\DecodedIni::parse()` |
| `opensim_db_for()` | `OpenSim\Economy\Currency::db()` |
| `opensim_get_avatar_session()` | `OpenSim\Economy\Currency::avatarSession()` |
| `opensim_user_alert()` | `OpenSim\Economy\Currency::userAlert()` |
| `noserver_save_transaction()` | `OpenSim\Economy\Currency::recordLocal()` |
| `noserver_get_balance()` | `OpenSim\Economy\Currency::balanceLocal()` |
| `currency_save_transaction()` | `OpenSim\Economy\Currency::saveTransaction()` |
| `currency_set_currency_balance()` | `OpenSim\Economy\Currency::setBalance()` |
| `currency_update_simulator_balance()` | `OpenSim\Economy\Currency::updateSimulatorBalance()` |
| `currency_move_money()` | `OpenSim\Economy\Currency::moveMoney()` |
| `currency_add_money()` | `OpenSim\Economy\Currency::addMoney()` |
| `currency_send_money()` | `OpenSim\Economy\Currency::sendMoney()` |
| `currency_get_balance()` | `OpenSim\Economy\Currency::balance()` |
| `currency_get_confirm_value()` | `OpenSim\Economy\Currency::confirmValue()` |
| `currency_process_transaction()` | `OpenSim\Economy\Currency::processTransaction()` |
| `currency_virtual_to_real()` | `OpenSim\Economy\Currency::virtualToReal()` |
| `currency_xmlrpc_call()` | `OpenSim\Economy\Currency::xmlrpcCall()` |
| `opensim_set_current_region()` | `OpenSim\Economy\Currency::setCurrentRegion()` |
| `opensim_get_server_info()` | `OpenSim\Economy\Currency::serverInfo()` |
| `opensim_check_secure_session()` | `OpenSim\Economy\Currency::checkSecureSession()` |
| `opensim_check_region_secret()` | `OpenSim\Economy\Currency::checkRegionSecret()` |
| `opensim_get_region_info()` | `OpenSim\Economy\Currency::regionInfo()` |
| `currency_has_key()` | `OpenSim\Economy\Currency::hasKey()` |
| `make_random_guid()` | `OpenSim\Economy\Currency::randomGuid()` |

Dropped on purpose: `do_not_sanitize()` (a no-op), `escape_url()` (a `htmlspecialchars()`: use `OpenSim\Security\Escape::attr()`),
`pascal_case()` (it never worked).

## The small functions of the old `OpenSim` class

| Before | Now |
| --- | --- |
| `OpenSim::is_true($value)` | `Result::isTrue($value)`, and `Result::isFalse($value)`: a no, which null is not |
| `isSuccess()`, `isError()` | `Result::isSuccess()`, `Result::isError()`: not the contrary of one another, null and an answer that says nothing are neither |
| `OpenSim::is_null_key($uuid)` | `Text\Uuid::isNullKey($uuid)` (the two keys for nobody) |
| `OpenSim::empty($value)` | `Text\Uuid::isEmpty($value)` |
| `w4os_demask($mask, $labels, $text)` | `Support\Bitmask::labels($mask, $labels)`; for a profile `Avatar::detail()['wantsList']` and `['skillsList']` |
| `OpenSim::validate_db_credentials($credentials)` | `Data\Connections::test($credentials)`: null when it connects, else why not |
| `OpenSim::sanitize_login_uri($address)` | `Hypergrid\Destination::loginUrl($address)`, `loginParts()` |
| `OpenSim::sanitize_uri_plus($url)` | `Hypergrid\Destination::sanitizePlus($url)` |
| `OpenSim::grid_info($gateway)` | `Grid::gridInfo()` |

## Constants

`NULL_KEY` and the `TPLINK_*` flags are still global (`includes/constants.php`, loaded by the autoloader); the flags are also
`OpenSim\Hypergrid\Destination::LOCAL`, `HG`, `V3HG`, `HOP`, `TXT`, `APPTP`, `MAP`, `ALL`, `AS_ARRAY`.

## State that was global

- `$OSSEARCH_CACHE` (link_region, get_region): kept by `Gatekeeper`.
- `$request_key`: `OpenSim\Http\Respond::$requestKey`.
- `Engine_Settings::get('engine.Beta.enable_self_signed')`: `OpenSim\Hypergrid\Gatekeeper::$verifyPeer = false`.
- `$CurrencyDB`: `Currency::currencyDb()` (its own database when `CURRENCY_DB_*` are defined, else `Currency::db()`, which asks the
  consumer's `db_for()` or the globals `$OpenSimDB`, `$robust_db`). `Currency::currencyDb($handle)` gives it the handle.
