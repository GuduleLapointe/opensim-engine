<?php

namespace OpenSim\Health;

use OpenSim\Config\GridConfig;

/**
 * Health checks of an OpenSimulator grid, for whoever serves it: the addresses its settings give and what each answers, what
 * an install needs. A tool for every consumer of the engine, alone or together (a web page, a command, a plugin): it takes
 * data and gives data (groups of rows), it shows nothing.
 *
 * A group is a section of the ini files (its title is the name of the section) of one file (its `file`), and a row a setting of
 * it, its label the key as the ini files write it: whoever reads a check must not wonder where to look for a setting. The first
 * group is what the grid says at its login address (get_grid_info), which works without any local config; the files of the config
 * come next, when there is one, and what the grid says is compared with what they say (a difference means a restart is needed, or
 * a problem of config).
 *
 * An address is asked where its redirections lead, what counts is what it answers (the certificate is only reported, with
 * `cert`), and what is right for an address is what is expected of it: a page answers 2xx, an address that is not a page answers
 * 418 (a teapot), a service is called twice, with nothing (it must refuse) and with what it expects (it must answer 200).
 *
 * @package magicoli/opensim-engine
 * @license AGPLv3
 */

class Health
{
    /** How long an address has to answer, in seconds. */
    const TIMEOUT = 3;

    /**
     * The settings of [GridInfoService] that are addresses, shown first, in this order (the one of Robust.HG.ini.example, and
     * `search`, the web page of the search the viewers read in get_grid_info), even when the grid gives none. It is not what the
     * simulators call for the search ([Search] SearchURL of OpenSim.ini).
     */
    const GRID_INFO = ['login', 'gatekeeper', 'welcome', 'economy', 'about', 'register', 'help', 'password', 'GridStatus', 'GridStatusRSS', 'web_profile_url', 'search'];

    /**
     * The addresses of the grid itself, which answer at /get_grid_info: the home of a grid (host:publicport) has no page of its own,
     * asked alone it is a 404. What is at the same address as the login is the same.
     */
    const GRID_ADDRESSES = ['login', 'gatekeeper', 'uas'];

    /** What a service answers to a call with nothing in it, or with the wrong method. */
    const BARE = [400, 405];

    /** What an address that is not a page answers: not served, and not an error (a teapot). */
    const TEAPOT = [418];

    /** An identifier that is a UUID and nobody has, to ask for what an avatar has (nothing). */
    const UNKNOWN_AVATAR = '00000000-0000-4000-8000-000000000000';

    /**
     * What the grid says of itself at its login address (`/get_grid_info`), as it says it: no value is made up. A grid that does
     * not answer says nothing.
     *
     * @param  string $login the login address of the grid
     * @return array<string,string> key => value, empty when the grid does not answer
     */
    public static function grid_info($login)
    {
        if (!self::is_url($login)) {
            return [];
        }
        $handle = curl_init(rtrim($login, '/') . '/get_grid_info');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            // What is asked here is what the grid says, whatever its certificate (the checks say it)
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($handle);
        $ok = curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;
        $previous = libxml_use_internal_errors(true);
        $xml = $ok && is_string($body) ? simplexml_load_string($body) : false;
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml ? array_map('strval', (array) $xml) : [];
    }

    /**
     * The names of the files the settings of a grid are in: the one of Robust (Robust.HG.ini, Robust.ini), the one of the
     * simulators and the one of the money server.
     *
     * @param  GridConfig|null $config
     * @return array{robust:string,opensim:string,money:string}
     */
    public static function names($config)
    {
        $robust = $config ? $config->file('Robust') : null;

        return ['robust' => $robust ? basename($robust) : 'Robust.ini', 'opensim' => 'OpenSim.ini', 'money' => 'MoneyServer.ini'];
    }

    /**
     * The addresses to ask, as groups of rows: what the grid says (get_grid_info), then, when the config of the grid is there, the
     * files it is in, the sections of each, the addresses they give, and the ones that are expected and not given, empty.
     *
     * @param  array{config?:?GridConfig,live?:array,login?:?string,owner?:?string} $in
     *   - config: the config of the grid (null when this machine has none)
     *   - live:   what the grid says at /get_grid_info, key => value
     *   - login:  the login address
     *   - owner:  the name (Firstname.Lastname) of an avatar of the grid, to ask a profile for
     * @return list<array{file:string,title:string,rows:list<array<string,mixed>>}>
     */
    public static function grid($in)
    {
        $config = $in['config'] ?? null;
        $live = array_filter($in['live'] ?? [], 'is_string');
        $login = (string) ($in['login'] ?? '');
        $owner = $in['owner'] ?? null;
        $file = self::names($config);
        $groups = [];

        // What the grid says: every value of get_grid_info, the login address first (it is where it is asked, and it is asked even
        // when the grid does not answer). The addresses are asked, the other values are shown
        if ($login !== '') {
            $live['login'] = $login;
        }
        $home = self::origin($login);
        $keys = array_values(array_unique(array_merge(array_intersect(self::GRID_INFO, array_keys($live)), array_keys($live))));
        $rows = [];
        foreach ($keys as $key) {
            // The profile is asked here without a name only: asked with the name of an avatar, it is in the config below
            array_push($rows, ...self::address($key, $live[$key], $owner, $home));
        }
        if ($rows) {
            $groups[] = ['file' => 'get_grid_info', 'title' => '', 'rows' => $rows];
        }
        if (!$config) {
            return $groups;
        }

        // The Robust config: what it says of the grid, compared with what the grid says. A difference is a restart that was not
        // done, or a setting that does not reach the grid
        if ($config->has('Robust')) {
            $given = array_filter($config->section('GridInfoService'), fn($value) => $value !== '');
            $rows = [];
            foreach (array_values(array_unique(array_merge(self::GRID_INFO, array_keys($given)))) as $key) {
                $value = (string) ($given[$key] ?? '');
                if ($value === '' || !$live) {
                    // Nothing to compare with when the grid does not answer: the addresses are asked from here, the profile
                    // with the name of an avatar too
                    array_push($rows, ...self::address($key, $value, $owner, $home, true));
                    continue;
                }
                $same = isset($live[$key]) && rtrim($live[$key], '/') === rtrim($value, '/');
                // The profile is one row: the address as the config says it, the link and the test those of the owner of the grid
                $profile = $key === 'web_profile_url' && $owner !== null && str_contains($value, '[AGENT_NAME]');
                if ($profile && $same) {
                    $rows[] = self::profile_row($key, $value, $owner);
                    continue;
                }
                $rows[] = [
                    'label' => $key,
                    (self::is_url($value) ? 'url' : 'text') => $profile ? str_replace('[AGENT_NAME]', $owner, $value) : $value,
                    'level' => $same ? 'ok' : 'error',
                    'status' => $same ? _('OK') : _('differs'),
                    'ms' => null,
                ] + ($profile ? ['display' => $value] : []) + ($key === 'search' ? ['tag' => _('Web search')] : []) + ($same ? [] : ['detail' => 'get_grid_info: ' . ($live[$key] ?? '')]);
            }
            $groups[] = ['file' => $file['robust'], 'title' => '[GridInfoService]', 'rows' => $rows];
            $groups[] = [
                'file' => $file['robust'],
                'title' => '[LoginService]',
                'rows' => [
                    ...self::address('DestinationGuide', (string) $config->find('LoginService', 'DestinationGuide'), $owner),
                    ...self::address('MessageUrl', (string) $config->find('LoginService', 'MessageUrl'), $owner),
                    self::login_search((string) $config->find('LoginService', 'SearchURL'), $given['search'] ?? ''),
                ],
            ];
        }

        // What the simulators call (OpenSim.ini): the search, the registration on a directory, the offline messages
        $search = (string) $config->find('Search', 'SearchURL');
        $groups[] = [
            'file' => $file['opensim'],
            'title' => '[Search]',
            'rows' => $search === '' ? [self::empty_row('SearchURL')] : self::xmlrpc('SearchURL', $search, 'dir_places_query', ['flags' => 0, 'text' => 'health', 'query_start' => '0']),
        ];
        $rows = [];
        foreach ($config->keys('DataSnapshot', '/^DATA_SRV_/') as $key => $url) {
            array_push($rows, ...self::query($key, $url, 'service=offline&host=health.invalid&port=1'));
        }
        $groups[] = ['file' => $file['opensim'], 'title' => '[DataSnapshot]', 'rows' => $rows ?: [self::empty_row('DATA_SRV_*')]];
        $offline = (string) $config->find('Messaging', 'OfflineMessageURL');
        $groups[] = [
            'file' => $file['opensim'],
            'title' => '[Messaging]',
            'rows' => $offline === '' ? [self::empty_row('OfflineMessageURL')] : self::post('OfflineMessageURL', $offline, self::retrieve_messages(), 'RetrieveMessages/'),
        ];

        // The key of the currency, where the MoneyServer has it: never shown, only whether the grid has one. Without it the
        // currency is not served, an error for a grid that has a currency
        $module = strtolower((string) $config->find('Economy', 'economymodule'));
        $needed = $module !== '' && $module !== 'betagridlikemoneymodule';
        $has = trim((string) $config->find('MoneyServer', 'MoneyScriptAccessKey')) !== '';
        $groups[] = [
            'file' => $file['money'],
            'title' => '[MoneyServer]',
            'rows' => [['label' => 'MoneyScriptAccessKey', 'url' => '', 'level' => $has ? 'ok' : ($needed ? 'error' : 'none'), 'status' => $has ? _('OK') : ($needed ? _('missing') : ''), 'ms' => null]],
        ];

        return $groups;
    }

    /**
     * The rows of a setting that is an address: asked as a page, whatever it is for (the grid info of the login address and of
     * the gatekeeper, a teapot where the viewers only add a name, the profile with the name of an avatar and without any). A value
     * that is not an address is shown, an empty one is a row with its name only.
     *
     * @param  string      $key
     * @param  string      $value
     * @param  string|null $owner    the name of an avatar of the grid
     * @param  string      $home     the origin (scheme, host, port) of the grid, what is there alone is asked at /get_grid_info
     * @param  bool        $variants the profile is asked with the name of an avatar too
     * @return list<array<string,mixed>>
     */
    private static function address($key, $value, $owner, $home = '', $variants = false)
    {
        if ($value === '') {
            return [self::empty_row($key) + ($key === 'search' ? ['tag' => _('Web search')] : [])];
        }
        if (!self::is_url($value)) {
            return [['label' => $key, 'url' => '', 'text' => $value, 'level' => 'none', 'status' => '', 'ms' => null]];
        }
        $row = self::page($key, $value);
        if (in_array($key, self::GRID_ADDRESSES, true) || ($home !== '' && self::origin($value) === $home && trim((string) parse_url($value, PHP_URL_PATH), '/') === '')) {
            // What answers at these addresses is the grid info, the first thing the viewers ask
            $row['probe'] = rtrim($value, '/') . '/get_grid_info';
        } elseif ($key === 'economy') {
            // The address of the helpers, to which the viewers add the name of the script: no page of its own
            $row['expect'] = self::TEAPOT;
        } elseif ($key === 'search') {
            // The web page of the search, not the service the simulators call
            $row['tag'] = _('Web search');
        } elseif (str_contains($value, '[AGENT_NAME]')) {
            // What the grid says is the address without the name, which is not a page (a teapot); the one with the name of an avatar
            // is the one of the config
            if ($variants) {
                return [$owner === null ? ['label' => $key, 'url' => '', 'text' => $value, 'level' => 'none', 'status' => '', 'ms' => null] : self::profile_row($key, $value, $owner)];
            }
            $row['url'] = rtrim((string) preg_replace('#\[AGENT_NAME\].*$#', '', $value), '/');
            $row['expect'] = self::TEAPOT;

            return [$row];
        }

        return [$row];
    }

    /**
     * The profile of an avatar of the grid (its owner): shown as the config says it, with [AGENT_NAME], linked and asked with the
     * name of the owner.
     */
    private static function profile_row($key, $value, $owner)
    {
        return self::page($key, str_replace('[AGENT_NAME]', $owner, $value)) + ['display' => $value];
    }

    /**
     * Where an address is: scheme, host and port (the default one of the scheme when it gives none).
     *
     * @return string empty when it is not an address
     */
    private static function origin($url)
    {
        $parts = parse_url((string) $url);
        if (empty($parts['host']) || empty($parts['scheme'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);

        return $scheme . '://' . strtolower($parts['host']) . ':' . ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    }

    /**
     * `[LoginService] SearchURL`, the documented way to tell the viewers the web search (at login, and as a feature of the grid),
     * which Firestorm does not receive from there alone: `search` of [GridInfoService] is the real one, the one the checks use.
     * The same address as `search` is fine and not asked again; another address is the operator's, asked for what it is.
     *
     * @param string $value  SearchURL of [LoginService]
     * @param string $search search of [GridInfoService]
     */
    private static function login_search($value, $search)
    {
        if ($value === '') {
            return self::empty_row('SearchURL') + ['tag' => _('Web search')];
        }
        if ($search !== '' && rtrim($value, '/') === rtrim($search, '/')) {
            return ['label' => 'SearchURL', 'url' => $value, 'tag' => _('Web search'), 'level' => 'ok', 'status' => _('OK'), 'ms' => null];
        }

        return self::page('SearchURL', $value, _('Web search'));
    }

    /** The row of a setting that has no value: its name, nothing else. */
    public static function empty_row($label)
    {
        return ['label' => $label, 'url' => '', 'level' => 'none', 'status' => '', 'ms' => null];
    }

    /** A page: asked with a GET, it answers. */
    public static function page($label, $url, $tag = null)
    {
        return ['label' => $label, 'url' => $url, 'method' => 'get'] + ($tag ? ['tag' => $tag] : []);
    }

    /**
     * A service that answers XML-RPC, asked twice: with nothing, it must refuse, and with a request that makes sense (and asks
     * for nothing: an avatar that does not exist...) it must answer 200.
     *
     * @param  string               $label  what the rows are named
     * @param  string               $url    the address of the service
     * @param  string               $call   the method to call
     * @param  array<string,mixed>  $params its parameters, ints and strings
     * @return list<array<string,mixed>>
     */
    public static function xmlrpc($label, $url, $call, array $params)
    {
        return self::twice($label, $url, 'post', ['method' => 'xmlrpc', 'body' => self::xmlrpc_body($call, $params)], _('with valid XMLRPC request'));
    }

    /**
     * A service that takes data posted, asked twice: with nothing, it must refuse, and with data that makes sense it must answer
     * 200.
     *
     * @param  string $suffix what follows the address of the service to call it (offline.php/RetrieveMessages/)
     * @return list<array<string,mixed>>
     */
    public static function post($label, $url, $body, $suffix = '')
    {
        return self::twice($label, $url, 'post', ['method' => 'post', 'body' => $body] + ($suffix === '' ? [] : ['probe' => rtrim($url, '/') . '/' . $suffix]), _('with valid post data'));
    }

    /**
     * A service that takes its data in the address, asked twice: with nothing, it must refuse, and with a query that makes sense
     * it must answer 200.
     *
     * @return list<array<string,mixed>>
     */
    public static function query($label, $url, $query)
    {
        return self::twice($label, $url, 'get', ['method' => 'get', 'probe' => $url . (str_contains($url, '?') ? '&' : '?') . $query], _('with valid query'));
    }

    /**
     * The two rows of a service: the call with nothing, which must be refused, and the right one.
     *
     * @param  string               $how  how the service is called without data: get or post
     * @param  array<string,mixed>  $good what makes the right call (method, body, probe)
     * @return list<array<string,mixed>>
     */
    private static function twice($label, $url, $how, array $good, $tag)
    {
        return [
            ['label' => $label, 'url' => $url, 'method' => $how, 'body' => '', 'expect' => self::BARE, 'refuse' => true, 'tag' => _('without data')],
            ['label' => $label, 'url' => $url, 'tag' => $tag] + $good,
        ];
    }

    /** The request of the offline messages of an avatar, which has none. */
    public static function retrieve_messages()
    {
        return '<?xml version="1.0"?><UUID xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema"><Guid>' . self::UNKNOWN_AVATAR . '</Guid></UUID>';
    }

    /** The body of a call of XML-RPC, with one struct of ints and strings as parameter. */
    private static function xmlrpc_body($method, array $params)
    {
        $members = '';
        foreach ($params as $name => $value) {
            $type = is_int($value) ? 'int' : 'string';
            $members .= '<member><name>' . htmlspecialchars((string) $name, ENT_XML1) . "</name><value><$type>" . htmlspecialchars((string) $value, ENT_XML1) . "</$type></value></member>";
        }

        return '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($method, ENT_XML1) . '</methodName><params><param><value><struct>' . $members . '</struct></value></param></params></methodCall>';
    }

    /**
     * Ask every address, all at once. A row that has a `probe` is asked there, one that has a `skip` is not asked. An address is
     * asked where its redirections lead, whatever its certificate: `cert` says it is one this server does not trust, which is not
     * what counts. What it answers is `level` (ok for a 2xx or a code expected of it, error for anything else, none when it is not
     * defined), `status` (the HTTP code, OK, or the error of curl) and `ms`.
     *
     * @param  list<array{title:string,rows:list<array<string,mixed>>}> $groups
     * @return list<array{title:string,rows:list<array<string,mixed>>}>
     */
    public static function answers($groups)
    {
        $requests = [];
        foreach ($groups as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                if (($row['url'] ?? '') !== '' && !isset($row['skip']) && !isset($row['level'])) {
                    $requests["$g/$r"] = $row;
                }
            }
        }
        $results = self::ask($requests);

        foreach ($groups as $g => $group) {
            foreach ($group['rows'] as $r => $row) {
                $result = $results["$g/$r"] ?? null;
                if (isset($row['level'])) {
                    $verdict = [];
                } elseif (isset($row['skip'])) {
                    $verdict = ['level' => 'none', 'status' => '', 'ms' => null];
                } elseif ($result === null) {
                    $verdict = self::verdict(null, '', 0);
                } else {
                    $verdict = self::verdict($result['code'], $result['error'], $result['ms'], $result['errno'], $row['expect'] ?? [], !empty($row['refuse']));
                    // A certificate this server does not trust is told, it does not change what the address answers
                    if ($result['certificate'] !== 0) {
                        $verdict['cert'] = true;
                    }
                }
                $groups[$g]['rows'][$r] = array_diff_key($row, ['method' => 0, 'probe' => 0, 'skip' => 0, 'expect' => 0, 'refuse' => 0, 'body' => 0]) + $verdict + ['ms' => null];
            }
        }

        return $groups;
    }

    /**
     * Ask a set of addresses at once.
     *
     * @param  array<string,array<string,mixed>> $requests id => row (url, probe, method: get, post or xmlrpc, body)
     * @return array<string,array{code:int,errno:int,error:string,ms:int,certificate:int}>
     */
    private static function ask($requests)
    {
        if (!$requests) {
            return [];
        }
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $id => $row) {
            $handle = curl_init($row['probe'] ?? $row['url']);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_USERAGENT => 'OpenSimHealth',
                // What counts is where the address leads, not the redirection it has on the way
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                // Only the answer matters, not what follows it
                CURLOPT_WRITEFUNCTION => static fn($handle, $chunk) => strlen($chunk),
                // The certificate is not what is asked: an address with one this server does not trust is asked all the same,
                // and the result says so
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            if (($row['method'] ?? 'get') !== 'get') {
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $row['body'] ?? '',
                    CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
                ]);
            }
            curl_multi_add_handle($multi, $handle);
            $handles[$id] = $handle;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.2);
            }
        } while ($running && $status === CURLM_OK);

        // The code of the error of each address is told by the set, not by the handle
        $errors = [];
        while (($done = curl_multi_info_read($multi)) !== false) {
            $errors[spl_object_id($done['handle'])] = (int) $done['result'];
        }
        $results = [];
        foreach ($handles as $id => $handle) {
            $errno = $errors[spl_object_id($handle)] ?? 0;
            $results[$id] = [
                'code' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'errno' => $errno,
                'error' => curl_error($handle) ?: ($errno ? curl_strerror($errno) : ''),
                'ms' => (int) round(curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000),
                // How the certificate checks out, 0 when it is valid (or there is none)
                'certificate' => (int) curl_getinfo($handle, CURLINFO_SSL_VERIFYRESULT),
            ];
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);

        return $results;
    }

    /**
     * What an answer means: 2xx is fine, anything else is an error (a 404 is an address that is not there), unless it is what is
     * expected of this address. An address that does not answer gives the code of the error and what it says.
     *
     * @param  int|null $code   the HTTP code at the end of the redirections, 0 when nothing answered, null for an address that is not defined
     * @param  string   $error  what curl says when nothing answered
     * @param  int      $errno  the code of that error
     * @param  int[]    $expect the codes that are the right answer of this address, besides the 2xx
     * @param  bool     $refuse the address must refuse (called with nothing): only the codes expected are the right answer
     * @return array{level:string,status:string,ms:?int,detail?:string}
     */
    public static function verdict($code, $error, $ms, $errno = 0, $expect = [], $refuse = false)
    {
        if ($code === null) {
            return ['level' => 'none', 'status' => '', 'ms' => null];
        }
        if ($code === 0) {
            return ['level' => 'error', 'status' => 'cURL ' . _('error') . ' ' . $errno, 'ms' => null, 'detail' => $error];
        }
        $level = ($code >= 200 && $code < 300 && !$refuse) || in_array($code, $expect, true) ? 'ok' : 'error';

        return ['level' => $level, 'status' => (string) $code, 'ms' => $ms];
    }

    /** A row for what is there or not, and the file or the thing it is about. */
    public static function row($label, $good, $detail = '')
    {
        return ['label' => $label, 'url' => '', 'level' => $good ? 'ok' : 'error', 'status' => $good ? _('OK') : _('missing'), 'detail' => $detail, 'ms' => null];
    }

    /** A row for something that may not be defined: a file or a folder, read where it says. */
    public static function path($label, $path)
    {
        if ($path === '' || $path === null) {
            return ['label' => $label, 'url' => '', 'level' => 'none', 'status' => '', 'ms' => null];
        }

        return self::row($label, is_readable($path), $path);
    }

    /**
     * What an install needs of PHP, one row per extension.
     *
     * @return list<array<string,mixed>>
     */
    public static function extensions()
    {
        return [
            self::row('xmlrpc', function_exists('xmlrpc_encode'), 'extension or polyfill'),
            self::row('imagick', class_exists('Imagick'), 'text to image, web assets'),
            self::row('gettext', function_exists('gettext'), 'translations'),
        ];
    }

    /**
     * The files an install reads, and whether this user can.
     *
     * @param  string[] $paths
     * @return list<array<string,mixed>>
     */
    public static function files($paths)
    {
        $rows = [];
        foreach ($paths as $file) {
            if (is_file($file)) {
                $rows[] = self::row(basename($file), is_readable($file), $file);
            }
        }

        return $rows;
    }

    /** Whether a value is an address. */
    public static function is_url($value)
    {
        return (bool) preg_match('#^https?://#i', (string) $value);
    }
}
