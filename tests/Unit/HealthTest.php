<?php
/**
 * Health checks: what an answer means, the answers of real addresses (a small web server of the test), and the sections and
 * settings named as the ini files name them.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Health\Health;

/** A web server that answers the code its path says: /200, /404, /500, /redirect (to /200), /redirect404 (to /404); /needs-data refuses (400) what has no data. */
function code_server(): array
{
    $dir = sys_get_temp_dir() . '/code-server-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents(
        "$dir/router.php",
        '<?php $path = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
        if ($path === "redirect") { header("Location: /200", true, 308); return; }
        if ($path === "redirect404") { header("Location: /404", true, 308); return; }
        if ($path === "get_grid_info") { header("Content-Type: text/xml"); echo "<gridinfo><gridname>Alpha</gridname><login>http://play.example.org:8002/</login></gridinfo>"; return; }
        if ($path === "needs-data") { http_response_code(file_get_contents("php://input") === "" && empty($_SERVER["QUERY_STRING"]) ? 400 : 200); return; }
        http_response_code(is_numeric($path) ? (int) $path : 200); echo str_repeat("x", 5000);',
    );
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
    );
    // Until it listens (a refused connection is expected meanwhile)
    set_error_handler(static fn() => true);
    for ($try = 0; $try < 50 && !($connection = fsockopen('127.0.0.1', $port)); ++$try) {
        usleep(100_000);
    }
    restore_error_handler();
    if (!empty($connection)) {
        fclose($connection);
    }

    return [$process, $port];
}

describe('An address', function () {
    test(
        'is fine for 2xx, an error for anything else (a 404 is red) unless it is the answer expected, gives the error code when nothing answers, and is not defined when empty',
        function () {
            expect(Health::verdict(200, '', 12))
                ->toBe(['level' => 'ok', 'status' => '200', 'ms' => 12])
                ->and(Health::verdict(404, '', 3))
                ->toBe(['level' => 'error', 'status' => '404', 'ms' => 3])
                ->and(Health::verdict(500, '', 3)['level'])
                ->toBe('error')
                // What is expected of an address is not an error (a service called without parameters refuses, a teapot is not a page)
                ->and(Health::verdict(405, '', 3, 0, [400, 405]))
                ->toBe(['level' => 'ok', 'status' => '405', 'ms' => 3])
                ->and(Health::verdict(418, '', 3, 0, [418])['level'])
                ->toBe('ok')
                ->and(Health::verdict(404, '', 3, 0, [400, 405])['level'])
                ->toBe('error')
                // No answer is not an answer: the code of the error is, and what it says
                ->and(Health::verdict(0, 'Could not resolve host: x', 0, 6))
                ->toBe([
                    'level' => 'error',
                    'status' => 'cURL error 6',
                    'ms' => null,
                    'detail' => 'Could not resolve host: x',
                ])
                // An address that is not defined says nothing: it shows
                ->and(Health::verdict(null, '', 0))
                ->toBe(['level' => 'none', 'status' => '', 'ms' => null]);
        },
    );

    test('is asked, all of them, and the answer is the one of each, where the redirections lead', function () {
        [$process, $port] = code_server();
        $url = fn(string $path) => "http://127.0.0.1:$port/$path";
        $groups = Health::answers([
            [
                'title' => 'One',
                'rows' => [
                    ['label' => 'fine', 'url' => $url('200'), 'method' => 'get'],
                    ['label' => 'missing', 'url' => $url('404'), 'method' => 'get'],
                    ['label' => 'broken', 'url' => $url('500'), 'method' => 'get'],
                    ['label' => 'moved', 'url' => $url('redirect'), 'method' => 'get'],
                    ['label' => 'expected', 'url' => $url('404'), 'method' => 'get', 'expect' => [404]],
                    ['label' => 'movedaway', 'url' => $url('redirect404'), 'method' => 'get'],
                    ['label' => 'api', 'url' => $url('200'), 'method' => 'xmlrpc', 'body' => '<x/>'],
                    ['label' => 'probed', 'url' => $url('404'), 'probe' => $url('200'), 'method' => 'get'],
                    ['label' => 'template', 'url' => $url('404'), 'method' => 'get', 'skip' => true],
                    ['label' => 'empty', 'url' => '', 'method' => 'get'],
                    ['label' => 'nobody', 'url' => 'http://127.0.0.1:9/', 'method' => 'get'],
                ],
            ],
        ]);
        proc_terminate($process);
        $by = array_column($groups[0]['rows'], null, 'label');

        expect($by['fine']['status'])
            ->toBe('200')
            ->and($by['missing'])
            ->toMatchArray(['level' => 'error', 'status' => '404'])
            ->and($by['broken']['level'])
            ->toBe('error')
            // The redirection is anecdotal, the result at the end is what counts
            ->and($by['moved']['status'])
            ->toBe('200')
            ->and($by['movedaway']['status'])
            ->toBe('404')
            ->and($by['expected'])
            ->toMatchArray(['level' => 'ok', 'status' => '404'])
            ->and($by['api'])
            ->toMatchArray(['level' => 'ok', 'status' => '200'])
            ->and($by['probed'])
            ->toMatchArray(['level' => 'ok', 'url' => $url('404')])
            ->and($by['template'])
            ->toMatchArray(['level' => 'none', 'status' => ''])
            ->and($by['empty'])
            ->toMatchArray(['level' => 'none', 'status' => ''])
            ->and($by['nobody'])
            ->toMatchArray(['level' => 'error', 'status' => 'cURL error 7'])
            ->and($by['nobody']['detail'])
            ->not->toBe('')
            ->and($by['fine'])
            ->not->toHaveKeys(['method', 'probe', 'skip', 'expect', 'body']);
    });

    test(
        'is asked twice when it is a service: with nothing, it must refuse, with what it wants, it must answer',
        function () {
            [$process, $port] = code_server();
            $url = "http://127.0.0.1:$port/needs-data";
            $groups = Health::answers([
                [
                    'title' => 'Services',
                    'rows' => [
                        ...Health::xmlrpc('SearchURL', $url, 'dir_places_query', ['text' => 'a']),
                        ...Health::post('OfflineMessageURL', $url, '<x/>'),
                        ...Health::query('DATA_SRV_a', $url, 'service=offline'),
                        // A service that answers a call with nothing in it is not one that works: it is red
                        ...Health::xmlrpc('Lazy', "http://127.0.0.1:$port/200", 'm', []),
                    ],
                ],
            ]);
            proc_terminate($process);
            $rows = $groups[0]['rows'];
            $of = fn(string $label, string $tag) => array_values(
                array_filter($rows, fn($row) => $row['label'] === $label && $row['tag'] === $tag),
            )[0];

            expect($of('SearchURL', 'without data'))
                ->toMatchArray(['status' => '400', 'level' => 'ok'])
                ->and($of('SearchURL', 'with valid XMLRPC request'))
                ->toMatchArray(['status' => '200', 'level' => 'ok'])
                ->and($of('OfflineMessageURL', 'without data')['level'])
                ->toBe('ok')
                ->and($of('OfflineMessageURL', 'with valid post data'))
                ->toMatchArray(['status' => '200', 'level' => 'ok'])
                ->and($of('DATA_SRV_a', 'without data')['level'])
                ->toBe('ok')
                ->and($of('DATA_SRV_a', 'with valid query'))
                ->toMatchArray(['status' => '200', 'level' => 'ok'])
                ->and($of('Lazy', 'without data'))
                ->toMatchArray(['status' => '200', 'level' => 'error']);
        },
    );

    test(
        'is asked with the data in the right place: the body for a post, the query for a get, a path after the address when it takes one',
        function () {
            [$search, $good] = Health::xmlrpc(
                'SearchURL',
                'https://play.example.org/helpers/query.php',
                'dir_places_query',
                ['text' => 'a'],
            );
            [$offline, $retrieve] = Health::post(
                'OfflineMessageURL',
                'https://play.example.org/helpers/offline.php',
                '<UUID/>',
                'RetrieveMessages/',
            );
            [$bare, $registered] = Health::query(
                'DATA_SRV_a',
                'https://play.example.org/helpers/register.php',
                'service=offline&host=x',
            );

            expect($search)
                ->toMatchArray(['method' => 'post', 'body' => '', 'expect' => [400, 405], 'tag' => 'without data'])
                ->and($good)
                ->toMatchArray(['method' => 'xmlrpc', 'tag' => 'with valid XMLRPC request'])
                ->and($good['body'])
                ->toContain('<methodName>dir_places_query</methodName>')
                ->and($retrieve)
                ->toMatchArray([
                    'method' => 'post',
                    'body' => '<UUID/>',
                    'probe' => 'https://play.example.org/helpers/offline.php/RetrieveMessages/',
                    'url' => 'https://play.example.org/helpers/offline.php',
                ])
                ->and($bare)
                ->toMatchArray(['method' => 'get', 'tag' => 'without data'])
                ->and($registered)
                ->toMatchArray([
                    'method' => 'get',
                    'tag' => 'with valid query',
                    'probe' => 'https://play.example.org/helpers/register.php?service=offline&host=x',
                ]);
        },
    );
});

describe('What a grid says', function () {
    test('is what it answers at get_grid_info, as it says it, nothing when it does not answer', function () {
        [$process, $port] = code_server();
        $says = Health::gridInfo("http://127.0.0.1:$port/");
        $nothing = Health::gridInfo("http://127.0.0.1:$port/200");
        proc_terminate($process);

        expect($says)
            ->toBe(['gridname' => 'Alpha', 'login' => 'http://play.example.org:8002/'])
            ->and(Health::gridInfo('http://127.0.0.1:9/'))
            ->toBe([])
            ->and(Health::gridInfo('not an address'))
            ->toBe([]);
    });
});

describe('An address with a certificate this server does not trust', function () {
    test('answers its code, the check of the certificate said apart: what counts is the answer', function () {
        $openssl = trim((string) shell_exec('command -v openssl 2>/dev/null'));
        $dir = sys_get_temp_dir() . '/tls-' . bin2hex(random_bytes(4));
        mkdir($dir);
        exec(
            escapeshellarg($openssl) .
                " req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -keyout $dir/k.pem -out $dir/c.pem -days 1 -subj /CN=localhost 2>/dev/null",
            $out,
            $code,
        );
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        // A server that answers 200 to whatever is asked, with a certificate nobody signed
        $process = proc_open(
            [
                $openssl,
                's_server',
                '-accept',
                (string) $port,
                '-cert',
                "$dir/c.pem",
                '-key',
                "$dir/k.pem",
                '-www',
                '-quiet',
            ],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        set_error_handler(static fn() => true);
        for ($try = 0; $try < 50 && !($connection = fsockopen('127.0.0.1', $port)); ++$try) {
            usleep(100_000);
        }
        restore_error_handler();
        if (!empty($connection)) {
            fclose($connection);
        }

        $groups = Health::answers([
            ['title' => 'One', 'rows' => [['label' => 'tls', 'url' => "https://127.0.0.1:$port/", 'method' => 'get']]],
        ]);
        proc_terminate($process);
        $row = $groups[0]['rows'][0];

        expect($code)
            ->toBe(0)
            ->and($row)
            ->toMatchArray(['status' => '200', 'level' => 'ok', 'cert' => true])
            ->not->toHaveKey('note');
    })->skip(
        fn() => !str_contains((string) shell_exec('openssl version 2>/dev/null'), 'OpenSSL'),
        'no OpenSSL to make a certificate and serve it (LibreSSL does not do it the same way)',
    );
});

describe('sections of a grid', function () {
    /** A config as the files give it: Robust.HG.ini has all of it, as in the tests of the sections. */
    $config = fn(array $sections, array $roles = []) => new GridConfig(['Robust' => $sections] + $roles, [
        'Robust' => '/etc/opensim/grids/alpha/Robust.HG.ini',
    ]);
    $in = fn(array $sections, array $more = []) => ['config' => $config($sections)] + $more;
    $by = fn(array $groups) => array_column(
        array_map(fn($g) => ['title' => $g['file'] . ' ' . $g['title'], 'rows' => $g['rows']], $groups),
        'rows',
        'title',
    );
    $live = [
        'gridname' => 'Alpha World',
        'login' => 'http://play.example.org:8002/',
        'economy' => 'https://play.example.org/helpers',
        'welcome' => 'https://play.example.org/welcome',
        'web_profile_url' => 'https://play.example.org/profile/[AGENT_NAME]',
    ];

    test(
        'start with what the grid says, the address of login first, which is asked even when the grid says nothing',
        function () use ($by) {
            $groups = Health::grid([
                'live' => [
                    'gridname' => 'Alpha World',
                    'welcome' => 'https://play.example.org/welcome',
                    'login' => 'http://play.example.org:8002/',
                ],
                'login' => 'http://play.example.org:8002/',
            ]);
            $rows = array_column($groups[0]['rows'], null, 'label');

            expect(count($groups))
                ->toBe(1)
                ->and($groups[0]['file'])
                ->toBe('get_grid_info')
                ->and(array_column($groups[0]['rows'], 'label'))
                ->toBe(['login', 'welcome', 'gridname'])
                // The addresses are asked, a name is only shown
                ->and($rows['login'])
                ->toMatchArray([
                    'url' => 'http://play.example.org:8002/',
                    'probe' => 'http://play.example.org:8002/get_grid_info',
                ])
                ->and($rows['gridname'])
                ->toMatchArray(['url' => '', 'text' => 'Alpha World', 'level' => 'none']);

            // The grid does not answer: its login address is there all the same, to be asked
            $down = Health::grid(['login' => 'http://play.example.org:8002/']);

            expect(array_column($down[0]['rows'], 'label'))->toBe(['login']);
        },
    );

    test('ask the profile without a name only in what the grid says, which is not a page (a teapot)', function () use (
        $live,
    ) {
        $says = array_values(
            array_filter(
                Health::grid(['live' => $live, 'owner' => 'Jane.Doe'])[0]['rows'],
                fn($row) => $row['label'] === 'web_profile_url',
            ),
        );

        // The address without the name, whatever there is to ask with
        expect($says)
            ->toHaveCount(1)
            ->and($says[0])
            ->toMatchArray(['url' => 'https://play.example.org/profile', 'expect' => [418]])
            ->not->toHaveKey('display');
    });

    test(
        'ask the profile in one row in the config: shown as the config says it, linked and asked with the name of the owner of the grid',
        function () use ($live, $in) {
            $profile = fn(array $groups) => array_values(
                array_filter($groups[1]['rows'], fn($row) => $row['label'] === 'web_profile_url'),
            );
            $info = ['GridInfoService' => ['web_profile_url' => 'https://play.example.org/profile/[AGENT_NAME]']];
            $rows = $profile(Health::grid($in($info, ['live' => $live, 'owner' => 'Jane.Doe'])));

            expect($rows)
                ->toHaveCount(1)
                ->and($rows[0])
                ->toMatchArray([
                    'display' => 'https://play.example.org/profile/[AGENT_NAME]',
                    'url' => 'https://play.example.org/profile/Jane.Doe',
                    'method' => 'get',
                ])
                ->not->toHaveKeys(['tag', 'skip', 'expect']);

            // The grid does not answer (nothing from get_grid_info): it is asked all the same, in the same row
            $down = array_values(
                array_filter(
                    Health::grid($in($info, ['owner' => 'Jane.Doe']))[0]['rows'],
                    fn($row) => $row['label'] === 'web_profile_url',
                ),
            );

            expect($down)
                ->toHaveCount(1)
                ->and($down[0])
                ->toMatchArray([
                    'display' => 'https://play.example.org/profile/[AGENT_NAME]',
                    'url' => 'https://play.example.org/profile/Jane.Doe',
                    'method' => 'get',
                ]);

            // What the config says is not what the grid says: an error, the row is still the one of the owner
            $differs = $profile(
                Health::grid(
                    $in(
                        ['GridInfoService' => ['web_profile_url' => 'https://other.example.org/profile/[AGENT_NAME]']],
                        ['live' => $live, 'owner' => 'Jane.Doe'],
                    ),
                ),
            );

            expect($differs[0])
                ->toMatchArray([
                    'level' => 'error',
                    'status' => 'differs',
                    'display' => 'https://other.example.org/profile/[AGENT_NAME]',
                    'url' => 'https://other.example.org/profile/Jane.Doe',
                ])
                ->and($differs[0]['detail'])
                ->toBe('get_grid_info: https://play.example.org/profile/[AGENT_NAME]');

            // No account to read: the address as it is, not asked
            $none = $profile(Health::grid($in($info, ['live' => $live])));

            expect($none[0])->toMatchArray([
                'level' => 'ok',
                'status' => 'OK',
                'url' => 'https://play.example.org/profile/[AGENT_NAME]',
            ]);
        },
    );

    test(
        'are asked at /get_grid_info when they are the home of the grid (host:publicport), which answers nothing alone',
        function () {
            $groups = Health::grid([
                'live' => [
                    'login' => 'http://play.example.org:8002/',
                    'uas' => 'http://play.example.org:8002/',
                    'home' => 'http://play.example.org:8002',
                    'about' => 'http://play.example.org:8002/about',
                    'help' => 'https://play.example.org/help',
                ],
                'login' => 'http://play.example.org:8002/',
            ]);
            $rows = array_column($groups[0]['rows'], null, 'label');

            expect($rows['uas']['probe'])
                ->toBe('http://play.example.org:8002/get_grid_info')
                ->and($rows['home']['probe'])
                ->toBe('http://play.example.org:8002/get_grid_info')
                // A page of the same server is a page
                ->and($rows['about'])
                ->not->toHaveKey('probe')
                ->and($rows['help'])
                ->not->toHaveKey('probe');
        },
    );

    test(
        'expect a teapot where the viewers only add a name, and tell the web search (search, what the viewers read) from the API',
        function () use ($live) {
            $rows = array_column(
                Health::grid(['live' => $live + ['search' => 'https://play.example.org/search/']])[0]['rows'],
                null,
                'label',
            );

            expect($rows['economy']['expect'])
                ->toBe([418])
                ->and($rows['search'])
                ->toMatchArray(['tag' => 'Web search', 'method' => 'get']);
        },
    );

    test(
        'are the ones of the config by file, named as the ini files do, when there is one, the expected addresses of [GridInfoService] first',
        function () use ($in, $by, $live) {
            $groups = Health::grid(
                $in(
                    [
                        'GridInfoService' => [
                            'gridname' => 'Alpha World',
                            'economy' => 'https://play.example.org/helpers',
                            'partner' => 'https://partner.example.org/',
                        ],
                        'LoginService' => ['DestinationGuide' => 'https://play.example.org/helpers/guide.php'],
                        'DataSnapshot' => ['DATA_SRV_Alpha' => 'https://play.example.org/helpers/register.php'],
                    ],
                    ['live' => $live],
                ),
            );
            $rows = $by($groups);

            expect(array_map(fn($g) => $g['file'] . ' ' . $g['title'], $groups))
                ->toBe([
                    'get_grid_info ',
                    'Robust.HG.ini [GridInfoService]',
                    'Robust.HG.ini [LoginService]',
                    'OpenSim.ini [Search]',
                    'OpenSim.ini [DataSnapshot]',
                    'OpenSim.ini [Messaging]',
                    'MoneyServer.ini [MoneyServer]',
                ])
                // The ones of Robust.HG.ini.example, not what the example does not have (uas is the one of a standalone)
                ->and(array_column($rows['Robust.HG.ini [GridInfoService]'], 'label'))
                ->toBe([
                    'login',
                    'gatekeeper',
                    'welcome',
                    'economy',
                    'about',
                    'register',
                    'help',
                    'password',
                    'GridStatus',
                    'GridStatusRSS',
                    'web_profile_url',
                    'search',
                    'gridname',
                    'partner',
                ])
                ->and(array_column($rows['Robust.HG.ini [LoginService]'], 'label'))
                ->toBe(['DestinationGuide', 'MessageUrl', 'SearchURL']);
        },
    );

    test(
        'compare what the grid says with what the config says: the same is fine, a difference is an error that says what the grid says',
        function () use ($in, $by, $live) {
            $rows = array_column(
                $by(
                    Health::grid(
                        $in(
                            [
                                'GridInfoService' => [
                                    'gridname' => 'Alpha World',
                                    'login' => 'http://play.example.org:8002',
                                    'economy' => 'https://other.example.org/helpers',
                                    'about' => 'https://play.example.org/about',
                                ],
                                'Search' => [],
                            ],
                            ['live' => $live],
                        ),
                    ),
                )['Robust.HG.ini [GridInfoService]'],
                null,
                'label',
            );

            expect($rows['login'])
                ->toMatchArray(['level' => 'ok', 'status' => 'OK', 'url' => 'http://play.example.org:8002'])
                ->and($rows['gridname'])
                ->toMatchArray(['level' => 'ok', 'status' => 'OK', 'text' => 'Alpha World'])
                // The config says one thing and the grid another: a restart not done, or a config that does not reach it
                ->and($rows['economy'])
                ->toMatchArray([
                    'level' => 'error',
                    'status' => 'differs',
                    'url' => 'https://other.example.org/helpers',
                    'detail' => 'get_grid_info: https://play.example.org/helpers',
                ])
                // The config says it and the grid does not
                ->and($rows['about'])
                ->toMatchArray(['level' => 'error', 'status' => 'differs', 'detail' => 'get_grid_info: '])
                // Nothing in the config: nothing to compare
                ->and($rows['help'])
                ->toMatchArray(['level' => 'none', 'url' => '']);
        },
    );

    test(
        'ask the addresses of the config from here when the grid does not answer, nothing to compare with',
        function () use ($in, $by) {
            $rows = array_column(
                $by(Health::grid($in(['GridInfoService' => ['economy' => 'https://play.example.org/helpers']])))[
                    'Robust.HG.ini [GridInfoService]'
                ],
                null,
                'label',
            );

            expect($rows['economy'])->toMatchArray([
                'url' => 'https://play.example.org/helpers',
                'method' => 'get',
                'expect' => [418],
            ]);
        },
    );

    test(
        'have no file of Robust in them when this machine has none, the grid info and the rest are there all the same',
        function () use ($live) {
            $config = new GridConfig([
                'Helpers' => ['Search' => ['SearchURL' => 'https://play.example.org/helpers/query.php']],
            ]);
            $groups = Health::grid(['config' => $config, 'live' => $live]);

            expect(array_column($groups, 'file'))->toBe([
                'get_grid_info',
                'OpenSim.ini',
                'OpenSim.ini',
                'OpenSim.ini',
                'MoneyServer.ini',
            ]);
        },
    );

    test('are in the file of Robust the grid has, whichever it is', function () use ($in) {
        $file = fn($robust) => Health::names(
            $robust === null ? null : new GridConfig(['Robust' => []], ['Robust' => "/etc/$robust"]),
        )['robust'];

        expect($file('Robust.ini'))
            ->toBe('Robust.ini')
            ->and($file('Robust.HG.ini'))
            ->toBe('Robust.HG.ini')
            ->and($file(null))
            ->toBe('Robust.ini');
    });

    test(
        'call each service the OpenSim.ini gives twice, in its own section: the search (Search), the registration (DataSnapshot), the offline messages (Messaging)',
        function () use ($in, $by) {
            $rows = $by(
                Health::grid(
                    $in([
                        'Search' => ['SearchURL' => 'https://play.example.org/helpers/query.php'],
                        'DataSnapshot' => ['DATA_SRV_a' => 'https://play.example.org/helpers/register.php'],
                        'Messaging' => ['OfflineMessageURL' => 'https://play.example.org/helpers/offline.php'],
                    ]),
                ),
            );
            $tags = fn(string $section) => array_column($rows["OpenSim.ini $section"], 'tag');

            expect($tags('[Search]'))
                ->toBe(['without data', 'with valid XMLRPC request'])
                ->and($tags('[DataSnapshot]'))
                ->toBe(['without data', 'with valid query'])
                ->and($tags('[Messaging]'))
                ->toBe(['without data', 'with valid post data'])
                // The web search of Robust is not among them: it is another setting, in another file
                ->and(array_column($rows['OpenSim.ini [Search]'], 'label'))
                ->toBe(['SearchURL', 'SearchURL']);
        },
    );

    test(
        'have the web search where each file says it: search in get_grid_info and Robust, SearchURL in LoginService, the API in OpenSim.ini',
        function () use ($in, $live) {
            $groups = Health::grid(
                $in(
                    [
                        'GridInfoService' => ['search' => 'https://play.example.org/search/'],
                        'LoginService' => ['SearchURL' => 'https://play.example.org/search/'],
                        'Search' => ['SearchURL' => 'https://play.example.org/helpers/query.php'],
                    ],
                    ['live' => $live + ['search' => 'https://play.example.org/search/']],
                ),
            );
            $where = [];
            foreach ($groups as $group) {
                foreach ($group['rows'] as $row) {
                    if (in_array($row['label'], ['search', 'SearchURL'], true)) {
                        $where[] =
                            preg_replace(
                                '/\s+/',
                                ' ',
                                trim($group['file'] . ' ' . $group['title'] . ' ' . $row['label']),
                            ) .
                            ' ' .
                            ($row['tag'] ?? '');
                    }
                }
            }

            // Each in its own file and section, the two pages of the web tagged, the calls of the API as calls
            expect($where)->toBe([
                'get_grid_info search Web search',
                'Robust.HG.ini [GridInfoService] search Web search',
                'Robust.HG.ini [LoginService] SearchURL Web search',
                'OpenSim.ini [Search] SearchURL without data',
                'OpenSim.ini [Search] SearchURL with valid XMLRPC request',
            ]);
        },
    );

    test(
        'say SearchURL of the login service fine when it is search of the grid info, and ask it for what it is when it is another address',
        function () use ($in, $live) {
            $row = function (array $login, array $info) use ($in, $live) {
                $groups = Health::grid($in(['GridInfoService' => $info, 'LoginService' => $login], ['live' => $live]));
                $rows = array_values(
                    array_filter(
                        array_merge(
                            ...array_column(array_filter($groups, fn($g) => $g['title'] === '[LoginService]'), 'rows'),
                        ),
                        fn($r) => $r['label'] === 'SearchURL',
                    ),
                );

                return $rows[0];
            };

            expect(
                $row(
                    ['SearchURL' => 'https://play.example.org/search'],
                    ['search' => 'https://play.example.org/search/'],
                ),
            )
                ->toMatchArray(['level' => 'ok', 'status' => 'OK'])
                ->not->toHaveKey('method')
                // Two addresses are allowed (the operator's), each is tested for what it is, and `search` is the one the grid says
                ->and($row(['SearchURL' => 'https://a.example.org/'], ['search' => 'https://b.example.org/']))
                ->toMatchArray(['method' => 'get', 'tag' => 'Web search', 'url' => 'https://a.example.org/'])
                ->not->toHaveKey('level')
                ->and($row(['SearchURL' => 'https://a.example.org/'], []))
                ->toMatchArray(['method' => 'get', 'tag' => 'Web search', 'url' => 'https://a.example.org/'])
                ->and($row([], ['search' => 'https://b.example.org/']))
                ->toMatchArray(['level' => 'none', 'url' => '', 'tag' => 'Web search']);
        },
    );

    test(
        'say whether the grid has the key of its currency, never what it is, and an error for a grid with a currency that has none',
        function () use ($in, $by) {
            $row = fn(array $sections) => array_column(
                $by(Health::grid($in($sections)))['MoneyServer.ini [MoneyServer]'],
                null,
                'label',
            )['MoneyScriptAccessKey'];
            $key = ['MoneyServer' => ['MoneyScriptAccessKey' => 'a-secret-key']];

            expect($row($key))
                ->toMatchArray(['level' => 'ok', 'status' => 'OK'])
                ->and(json_encode($row($key)))
                ->not->toContain('a-secret-key')
                ->and($row(['Economy' => ['economymodule' => 'Gloebit']]))
                ->toMatchArray(['level' => 'error', 'status' => 'missing'])
                // A grid without a currency needs none, and says nothing
                ->and($row(['Economy' => ['economymodule' => 'BetaGridLikeMoneyModule']]))
                ->toMatchArray(['level' => 'none', 'status' => '']);
        },
    );
});

describe('What an install needs', function () {
    test(
        'is said one row per thing: the extensions of PHP, the files it reads, a path that is not defined',
        function () {
            $dir = sys_get_temp_dir() . '/health-' . bin2hex(random_bytes(4));
            mkdir($dir);
            file_put_contents("$dir/Robust.ini", '');
            $extensions = array_column(Health::extensions(), null, 'label');

            expect($extensions['xmlrpc']['level'])
                ->toBeIn(['ok', 'error'])
                ->and(Health::files(["$dir/Robust.ini", "$dir/absent.ini"]))
                ->toBe([Health::row('Robust.ini', true, "$dir/Robust.ini")])
                ->and(Health::path('GuideSource', ''))
                ->toMatchArray(['label' => 'GuideSource', 'status' => '', 'level' => 'none'])
                ->and(Health::path('GuideSource', "$dir/Robust.ini")['level'])
                ->toBe('ok');
        },
    );
});
