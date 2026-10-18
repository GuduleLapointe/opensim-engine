# What the engine had for the WordPress plugin and does not have anymore

The state before the clean-up is the tag `w4os-legacy` (`git show w4os-legacy:class-hypevents.php`). The plugin w4os keeps its own copy
of these classes (`helpers/engine/`). Nothing was deleted without its logic being somewhere: what is not in the engine now is listed
here, with what replaces it, or that nothing does yet.

| File (tag `w4os-legacy`) | What it did | Now |
| --- | --- | --- |
| `class-hypevents.php` | HYPEvents: fetching, parsing and formatting the events of the calendars (2do.events), writing them in the search tables | **not ported** (the helpers have `eventsparser.php`, which still uses it) |
| `class-search.php` | OpenSimSearch: the connection to its database, the creation and the updates of its tables | tables read by `Data\Database::Search`; creation and updates **not ported** |
| `class-currency.php`, `class-economy.php` | The currency of the helpers, the money server | the functions are `OpenSim\Economy\Currency`; the classes **not ported** |
| `class-flux.php` | Formatting of posts for a social stream (titles, auto links, relative times) | **not ported** (the project opensim-flux) |
| `class-form.php`, `class-form-field.php` | The forms of the admin pages: fields, steps, errors, HTML of each type | the questions are `Forms\FieldSet`; the HTML of the fields is **not ported** |
| `class-installation-wizard.php` | The setup wizard of the plugin | the setup of the kit (`opensim setup`) |
| `class-engine-settings.php` | The settings of the engine (ini in a config folder, credentials in JSON) | the official ini files (`Config\GridConfig`), `Helpers.ini` for the rest; the credentials file is **not ported** |
| `class-avatar.php`, `class-region.php`, `class-grid.php`, `class-robust.php`, `class-service.php`, `class-opensim.php` | Models and connections of the plugin | `Models\Avatar`, `Models\Region`, `Models\Parcel`, `Data\Database`, `Console\...` |
| `class-ini.php` | Ini parsing | `Config\ConfigReader`, `Config\IniEditor` |
| `class-xmlrpc-client.php` | A small XML-RPC client | **not ported** (`Hypergrid\Gatekeeper::post()` does the calls the engine needs) |
| `class-engine-exceptions.php` | `OpenSim_Exception`, `OpenSim_Error` | **not ported** |
