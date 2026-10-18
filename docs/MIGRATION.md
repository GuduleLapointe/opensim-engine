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

## Constants

`NULL_KEY` and the `TPLINK_*` flags are still global (`includes/constants.php`, loaded by the autoloader); the flags are also
`OpenSim\Hypergrid\Destination::LOCAL`, `HG`, `V3HG`, `HOP`, `TXT`, `APPTP`, `MAP`, `ALL`, `AS_ARRAY`.

## State that was global

- `$OSSEARCH_CACHE` (link_region, get_region): kept by `Gatekeeper`.
- `$request_key`: `OpenSim\Http\Respond::$requestKey`.
- `Engine_Settings::get('engine.Beta.enable_self_signed')`: `OpenSim\Hypergrid\Gatekeeper::$verifyPeer = false`.
- `$CurrencyDB`: `Currency::currencyDb()` (its own database when `CURRENCY_DB_*` are defined, else `Currency::db()`, which asks the
  consumer's `db_for()` or the globals `$OpenSimDB`, `$robust_db`). `Currency::currencyDb($handle)` gives it the handle.
