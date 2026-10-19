# What the engine had for the WordPress plugin and does not have anymore

The state before the clean-up is the tag `w4os-legacy` (`git show w4os-legacy:class-hypevents.php`). The plugin w4os keeps its own copy
of these classes (`helpers/engine/`). Nothing was deleted without its logic being somewhere: what is not in the engine now is listed
here, with what replaces it, or that nothing does yet.

| File (tag `w4os-legacy`) | What it did | Now |
| --- | --- | --- |
| `class-hypevents.php` | HYPEvents: fetching, parsing and formatting the events of the calendars (2do.events), writing them in the search tables | `Services\EventsParser` (the events of a HYPEvents calendar into the search); the formatting for a social stream is **not ported** |
| `class-search.php` | OpenSimSearch: the connection to its database, the creation and the updates of its tables | `Data\Database::Search` for the connection, `Services\SearchTables` for the creation and the updates of the tables |
| `class-currency.php`, `class-economy.php` | The currency of the helpers, the money server | `Economy\Currency` (an instance, configured by `Economy\CurrencySettings` from the files of the grid), `Services\CurrencyServer` for the calls of the viewers and the simulators; the classes **not ported** |
| `class-flux.php` | Formatting of posts for a social stream (titles, auto links, relative times) | **not ported** (the project opensim-flux) |
| `class-form.php`, `class-form-field.php` | The forms of the admin pages: fields, steps, errors, HTML of each type | the questions are `Forms\FieldSet`; the HTML of the fields is **not ported** |
| `class-installation-wizard.php` | The setup wizard of the plugin | the setup of the kit (`opensim setup`) |
| `class-engine-settings.php` | The settings of the engine (ini in a config folder, credentials in JSON) | the official ini files (`Config\GridConfig`), `Helpers.ini` for the rest; the credentials file is **not ported** |
| `class-avatar.php`, `class-region.php`, `class-grid.php`, `class-robust.php`, `class-service.php`, `class-opensim.php` | Models and connections of the plugin | `Models\Avatar`, `Models\Region`, `Models\Parcel`, `Data\Database`, `Console\...` |
| `class-ini.php` | Ini parsing | `Config\ConfigReader`, `Config\IniEditor` |
| `class-opensim.php`, its small functions | `is_true`, `is_null_key`, `empty`, `demask`, `validate_db_credentials`, `sanitize_login_uri`, `sanitize_uri_plus`, `parse_args`, `fast_xml` | `Result::isTrue()` and `isFalse()` (null is neither), `Text\Uuid::isNullKey()` and `isEmpty()`, `Support\Bitmask::labels()` and `names()` (the wants and the skills of a profile, the flags of a region), `Data\Connections::test()`, `Hypergrid\Destination::loginUrl()` and `loginParts()`, `Destination::sanitizePlus()`; `parse_args` and `fast_xml` **not ported**: the query arguments are the request's, and `fast_xml` only fetched an address and parsed it (`Network\GridInfoReader::get()`) |
| `class-xmlrpc-client.php` | A small XML-RPC client | **not ported** (`Hypergrid\Gatekeeper::post()` does the calls the engine needs) |
| `class-engine-exceptions.php` | `OpenSim_Exception`, `OpenSim_Error` | **not ported** |

## The scripts of the helpers, now services

What `query.php`, `register.php`, `parser.php`, `eventsparser.php`, `offline.php`, `currency.php`, `landtool.php`, `motd.php`, `textgen.php` and the guide did is in `OpenSim\Services\` and `OpenSim\Api\`, served by `Api\Handler`. What changed on the way, or was dropped:

| Before | Now |
| --- | --- |
| `register.php` answered, then `sleep(30)` and read the snapshot | `Api::register()` answers; `Handler::terminate()` (`Api::finish()`) reads the snapshot once the response is sent, after the same pause (`ClockInterface::sleep()`) |
| `ossearch_parse_if_due()` and its stamp file in the temporary folder | `Scheduling\Scheduler::tick()` and the tasks `search-parse` and `events-parse` (`Services\SearchTasks`) |
| `ossearch_answer_sent()` (`fastcgi_finish_request`) | the application sends the response, then calls `terminate()` |
| `Respond::$requestKey`, a cache to tell a same error once: set by nobody | **not ported** |
| `SaveMessage` checked `strpos($xml, '?>') == -1`, which is never true | **not ported** (nothing to check) |
| `offline.php` mailed with `wp_mail()` inside WordPress | `Services\MailerInterface` (the application brings its own; `PhpMailer` by default) |
| the mail of an offline message escaped its own `<h4>`, and never wrote its introduction | the parts of the message are escaped, the introduction (group notice, invitation, offer, friendship) is written |
| `Currency::missing()` (is the XML-RPC extension there?) | **not ported**: the engine requires `phpxmlrpc/phpxmlrpc` |
| `Currency` was static and read global constants (`CURRENCY_*`), and `setBalance()` locked the table | an instance configured by `CurrencySettings`; the addition is made by the database (`balance = balance + :amount`); `userAlert()` called the simulator with a variable that did not exist |
| `OPENSIM_MAIL_SENDER`, `CURRENCY_RATE`... defined by the helpers | `MailSender`, `CurrencyRate`, `CurrencyRatePer`, `GloebitConversionThreshold`, `PodexMessage`, `PodexUrl` of `[Helpers]` in `Helpers.ini` |
| `textgen.php?string=...&fontsize=...&textcolor=...` | `Api\Legacy::textgen()` gives the standard `/text-images` what the objects in world send; the text is capped at 500 characters |
| the SQL of `LIMIT a,b` and `DELETE x FROM ... JOIN` | `LIMIT b OFFSET a` and `DELETE ... WHERE ... IN (SELECT ...)`, which every database understands |

