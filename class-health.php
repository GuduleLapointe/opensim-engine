<?php
/**
 * Health checks of an OpenSimulator grid, for whoever serves it: the addresses its settings give and what each answers, what
 * an install needs. A tool for every consumer of the engine, alone or together (a web page, a command, a plugin): it takes
 * data and gives data (groups of rows), it shows nothing.
 *
 * A group is a section of the ini files, its title the name of the section, and a row a setting of it, its label the key as
 * the ini files write it: whoever reads a check must not wonder where to look for a setting. An address is asked where its
 * redirections lead, the certificate is not what is asked (it is only reported), and what is right for an address is what is
 * expected of it: a page answers 2xx, a service called without its parameters or with the wrong method answers an error, a
 * service called with parameters that make sense answers 200, an address that is not a page answers 418, a teapot.
 *
 * @package magicoli/opensim-engine
 * @license AGPLv3
 */

class OpenSim_Health
{
    /** How long an address has to answer, in seconds. */
    const TIMEOUT = 3;

    /** The settings of [GridInfoService] that are addresses, shown first, in this order, even when the grid gives none. */
    const GRID_INFO = ['login', 'gatekeeper', 'uas', 'welcome', 'economy', 'about', 'register', 'help', 'password', 'SearchURL'];

    /** The addresses of the grid itself, which answer at /get_grid_info. */
    const GRID_ADDRESSES = ['login', 'gatekeeper', 'uas'];

    /** What a service answers to a call without its parameters or with the wrong method. */
    const BARE = [400, 405];

    /** What an address that is not a page answers: not served, and not an error (a teapot). */
    const TEAPOT = [418];

    /**
     * The addresses to ask, as groups of rows, from the settings of the grid: the sections of the ini files an address is in, the
     * addresses they give, and the ones that are expected and not given, empty (not defined).
     *
     * @param  array{sources?:array,live?:array,login?:?string,owner?:?string,null_key?:string} $in
     *   - sources: the ini files as read, file => section => key => value
     *   - live:    what the grid says at /get_grid_info
     *   - login:   the login address
     *   - owner:   the name (Firstname.Lastname) of an avatar of the grid, to ask a profile for
     * @return list<array{title:string,rows:list<array<string,mixed>>}>
     */
    public static function grid($in)
    {
        $sources = $in['sources'] ?? [];
        $live = $in['live'] ?? [];
        $login = $in['login'] ?? null;
        $owner = $in['owner'] ?? null;
        $null = $in['null_key'] ?? '00000000-0000-0000-0000-000000000000';
        $get = fn($section, $key) => (string) self::value($sources, $section, $key);

        $groups = [];

        // What the viewers read at the login address (/get_grid_info), else what the config of Robust says: every address of it
        $info = [];
        foreach ($sources as $ini) {
            foreach ($ini['GridInfoService'] ?? [] as $key => $value) {
                if (self::is_url($value)) {
                    $info[$key] ??= $value;
                }
            }
        }
        foreach ($live as $key => $value) {
            if (is_string($value) && self::is_url($value)) {
                $info[$key] = $value;
            }
        }
        if ($login) {
            $info['login'] = (string) $login;
        }
        $rows = [];
        foreach (array_values(array_unique(array_merge(self::GRID_INFO, array_keys($info)))) as $key) {
            $value = (string) ($info[$key] ?? '');
            $row = ['label' => $key, 'url' => $value, 'method' => 'get'];
            if (in_array($key, self::GRID_ADDRESSES, true) && $value !== '') {
                // What answers at these addresses is the grid info, the first thing the viewers ask
                $row['probe'] = rtrim($value, '/') . '/get_grid_info';
            } elseif ($key === 'economy') {
                // The address of the helpers, to which the viewers add the name of the script: no page of its own
                $row['expect'] = self::TEAPOT;
            } elseif ($key === 'SearchURL') {
                // The web page of the search, not the service the simulators call
                $row['tag'] = 'web search';
            } elseif (str_contains($value, '[AGENT_NAME]')) {
                // The profile: with the name of an avatar (there is always one, its owner), and without any name
                $with = $row;
                if ($owner === null) {
                    $with['skip'] = 'no avatar';
                } else {
                    $with['url'] = str_replace('[AGENT_NAME]', $owner, $value);
                }
                $rows[] = $with;
                $row['url'] = rtrim((string) preg_replace('#\[AGENT_NAME\].*$#', '', $value), '/');
                $row['expect'] = self::TEAPOT;
            }
            $rows[] = $row;
        }
        $groups[] = ['title' => '[GridInfoService]', 'rows' => $rows];

        $groups[] = [
            'title' => '[LoginService]',
            'rows' => [
                ['label' => 'DestinationGuide', 'url' => $get('LoginService', 'DestinationGuide'), 'method' => 'get'],
                ['label' => 'MessageUrl', 'url' => $get('LoginService', 'MessageUrl'), 'method' => 'get'],
            ],
        ];

        // What the simulators call (OpenSim.ini): the search, the registration on a directory, the offline messages
        $groups[] = [
            'title' => '[Search]',
            'rows' => self::api('SearchURL', $get('Search', 'SearchURL'), 'dir_places_query', ['flags' => 0, 'text' => 'health', 'query_start' => '0'], 'API'),
        ];
        $rows = [];
        foreach (self::keys($sources, 'DataSnapshot', '/^DATA_SRV_/') as $key => $url) {
            $rows[] = self::bare($key, $url);
        }
        $groups[] = ['title' => '[DataSnapshot]', 'rows' => $rows ?: [self::bare('DATA_SRV_*', '')]];
        $groups[] = ['title' => '[Messaging]', 'rows' => [self::bare('OfflineMessageURL', $get('Messaging', 'OfflineMessageURL'))]];

        // The key of the currency, where the MoneyServer has it: never shown, only whether the grid has one. Without it the
        // currency is not served, an error for a grid that has a currency
        $module = strtolower($get('Economy', 'economymodule'));
        $needed = $module !== '' && $module !== 'betagridlikemoneymodule';
        $has = trim($get('MoneyServer', 'MoneyScriptAccessKey')) !== '';
        $groups[] = [
            'title' => '[MoneyServer]',
            'rows' => [
                ['label' => 'MoneyScriptAccessKey', 'url' => '', 'level' => $has ? 'ok' : ($needed ? 'error' : 'none'), 'status' => $has ? _('defined') : _('not defined'), 'detail' => 'MoneyServer.ini', 'ms' => null],
            ],
        ];

        return $groups;
    }

    /** A service called without its parameters or with the wrong method must refuse. */
    public static function bare($label, $url, $tag = null)
    {
        return ['label' => $label, 'url' => $url, 'method' => 'get', 'expect' => self::BARE] + ($tag ? ['tag' => $tag] : []);
    }

    /**
     * A service that answers XML-RPC, as two rows: called with the wrong method it refuses, called with parameters that make sense
     * (and ask for nothing: an avatar that does not exist...) it answers 200.
     *
     * @param  string               $label  what the row is named
     * @param  string               $url    the address of the service
     * @param  string               $call   the method to call
     * @param  array<string,mixed>  $params its parameters, ints and strings
     * @param  string|null          $tag    a short word to tell it from another row of the same label
     * @return list<array<string,mixed>>
     */
    public static function api($label, $url, $call, array $params, $tag = null)
    {
        return [
            self::bare($label, $url, $tag),
            ['label' => $label, 'url' => $url, 'method' => 'xmlrpc', 'body' => self::xmlrpc($call, $params)] + ($tag ? ['tag' => $tag] : []),
        ];
    }

    /** The body of a call of XML-RPC, with one struct of ints and strings as parameter. */
    private static function xmlrpc($method, array $params)
    {
        $members = '';
        foreach ($params as $name => $value) {
            $type = is_int($value) ? 'int' : 'string';
            $members .= '<member><name>' . htmlspecialchars((string) $name, ENT_XML1) . "</name><value><$type>" . htmlspecialchars((string) $value, ENT_XML1) . "</$type></value></member>";
        }

        return '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars($method, ENT_XML1) . '</methodName><params><param><value><struct>' . $members . '</struct></value></param></params></methodCall>';
    }

    /**
     * Ask every address, all at once. A row that has a `probe` is asked there, one that has a `skip` is not asked and says why. An
     * address is asked where its redirections lead, whatever its certificate: `cert` says it is one this server does not trust, and
     * the level is then warn at best. What it answers is `level` (ok for a 2xx or a code expected of it, error for anything else,
     * none when it is not defined), `status` (the HTTP code, or the error of curl) and `ms`.
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
                    $verdict = ['level' => 'none', 'status' => $row['skip'], 'ms' => null];
                } elseif ($result === null) {
                    $verdict = self::verdict(null, '', 0);
                } else {
                    $verdict = self::verdict($result['code'], $result['error'], $result['ms'], $result['errno'], $row['expect'] ?? []);
                    // It answers, but with a certificate this server does not trust: a warning, less than an error
                    if ($result['certificate'] !== 0) {
                        $verdict['cert'] = true;
                        if ($verdict['level'] === 'ok') {
                            $verdict['level'] = 'warn';
                        }
                    }
                }
                $post = ($row['method'] ?? 'get') === 'xmlrpc' ? ['post' => true] : [];
                $groups[$g]['rows'][$r] = array_diff_key($row, ['method' => 0, 'probe' => 0, 'skip' => 0, 'expect' => 0, 'body' => 0]) + $post + $verdict + ['ms' => null];
            }
        }

        return $groups;
    }

    /**
     * Ask a set of addresses at once.
     *
     * @param  array<string,array<string,mixed>> $requests id => row (url, probe, method, body)
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
            if (($row['method'] ?? 'get') === 'xmlrpc') {
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
     * @return array{level:string,status:string,ms:?int,detail?:string}
     */
    public static function verdict($code, $error, $ms, $errno = 0, $expect = [])
    {
        if ($code === null) {
            return ['level' => 'none', 'status' => _('not defined'), 'ms' => null];
        }
        if ($code === 0) {
            return ['level' => 'error', 'status' => 'cURL ' . _('error') . ' ' . $errno, 'ms' => null, 'detail' => $error];
        }
        $level = ($code >= 200 && $code < 300) || in_array($code, $expect, true) ? 'ok' : 'error';

        return ['level' => $level, 'status' => (string) $code, 'ms' => $ms];
    }

    /** A row for what is there or not, and the file or the thing it is about. */
    public static function row($label, $good, $detail = '')
    {
        return ['label' => $label, 'url' => '', 'level' => $good ? 'ok' : 'error', 'status' => $good ? _('ok') : _('missing'), 'detail' => $detail, 'ms' => null];
    }

    /** A row for something that may not be defined: a file or a folder, read where it says. */
    public static function path($label, $path)
    {
        if ($path === '' || $path === null) {
            return ['label' => $label, 'url' => '', 'level' => 'none', 'status' => _('not defined'), 'ms' => null];
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

    /** The value of a setting: the first file that has it. */
    private static function value($sources, $section, $key)
    {
        foreach ($sources as $ini) {
            if (isset($ini[$section][$key]) && $ini[$section][$key] !== '') {
                return $ini[$section][$key];
            }
        }

        return null;
    }

    /**
     * The settings of a section whose key matches, with their keys, in every file.
     *
     * @return array<string,string> key => value
     */
    private static function keys($sources, $section, $pattern)
    {
        $found = [];
        foreach ($sources as $ini) {
            foreach ($ini[$section] ?? [] as $key => $value) {
                if ($value !== '' && preg_match($pattern, $key)) {
                    $found[$key] ??= $value;
                }
            }
        }

        return $found;
    }
}
