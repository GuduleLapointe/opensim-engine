<?php
/**
 * Health checks: what an answer means, the answers of real addresses (a small web server of the test), and the sections and
 * settings named as the ini files name them.
 */

require_once dirname(__DIR__, 2) . '/class-health.php';

/** A web server that answers the code its path says: /200, /404, /500, /redirect (to /200), /redirect404 (to /404); a POST is a 200. */
function code_server(): array
{
    $dir = sys_get_temp_dir() . '/code-server-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents(
        "$dir/router.php",
        '<?php $path = trim(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH), "/");
        if ($path === "redirect") { header("Location: /200", true, 308); return; }
        if ($path === "redirect404") { header("Location: /404", true, 308); return; }
        if ($path === "post-only") { http_response_code($_SERVER["REQUEST_METHOD"] === "POST" ? 200 : 405); return; }
        http_response_code(is_numeric($path) ? (int) $path : 200); echo str_repeat("x", 5000);',
    );
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $process = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
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
    test('is fine for 2xx, an error for anything else (a 404 is red) unless it is the answer expected, gives the error code when nothing answers, and is not defined when empty', function () {
        expect(OpenSim_Health::verdict(200, '', 12))
            ->toBe(['level' => 'ok', 'status' => '200', 'ms' => 12])
            ->and(OpenSim_Health::verdict(404, '', 3))
            ->toBe(['level' => 'error', 'status' => '404', 'ms' => 3])
            ->and(OpenSim_Health::verdict(500, '', 3)['level'])
            ->toBe('error')
            // What is expected of an address is not an error (a service called without parameters refuses, a teapot is not a page)
            ->and(OpenSim_Health::verdict(405, '', 3, 0, [400, 405]))
            ->toBe(['level' => 'ok', 'status' => '405', 'ms' => 3])
            ->and(OpenSim_Health::verdict(418, '', 3, 0, [418])['level'])
            ->toBe('ok')
            ->and(OpenSim_Health::verdict(404, '', 3, 0, [400, 405])['level'])
            ->toBe('error')
            // No answer is not an answer: the code of the error is, and what it says
            ->and(OpenSim_Health::verdict(0, 'Could not resolve host: x', 0, 6))
            ->toBe(['level' => 'error', 'status' => 'cURL error 6', 'ms' => null, 'detail' => 'Could not resolve host: x'])
            // An address that is not defined says nothing: it shows
            ->and(OpenSim_Health::verdict(null, '', 0))
            ->toBe(['level' => 'none', 'status' => '', 'ms' => null]);
    });

    test('is asked, all of them, and the answer is the one of each, where the redirections lead', function () {
        [$process, $port] = code_server();
        $url = fn(string $path) => "http://127.0.0.1:$port/$path";
        $groups = OpenSim_Health::answers([
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

    test('is asked as the service wants it: a call that makes sense for an API that answers one, bare for one that cannot be called bare', function () {
        [$process, $port] = code_server();
        $row = OpenSim_Health::api('SearchURL', "http://127.0.0.1:$port/post-only", 'dir_places_query', ['text' => 'a'], 'API');
        $bare = OpenSim_Health::bare('OfflineMessageURL', "http://127.0.0.1:$port/post-only");
        $groups = OpenSim_Health::answers([['title' => 'API', 'rows' => [$row, $bare]]]);
        proc_terminate($process);
        [$api, $refused] = $groups[0]['rows'];

        // The call that makes sense gets its 200, the bare one is refused (405) and that is what is expected
        expect($api)
            ->toMatchArray(['label' => 'SearchURL', 'tag' => 'API', 'status' => '200', 'level' => 'ok'])
            ->and($refused)
            ->toMatchArray(['label' => 'OfflineMessageURL', 'status' => '405', 'level' => 'ok'])
            ->not->toHaveKey('tag');
        expect($row['body'])->toContain('<methodName>dir_places_query</methodName>');
    });
});

describe('An address with a certificate this server does not trust', function () {
    test('answers its code, the check of the certificate said apart: what counts is the answer', function () {
        $openssl = trim((string) shell_exec('command -v openssl 2>/dev/null'));
        $dir = sys_get_temp_dir() . '/tls-' . bin2hex(random_bytes(4));
        mkdir($dir);
        exec(escapeshellarg($openssl) . " req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -keyout $dir/k.pem -out $dir/c.pem -days 1 -subj /CN=localhost 2>/dev/null", $out, $code);
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        // A server that answers 200 to whatever is asked, with a certificate nobody signed
        $process = proc_open([$openssl, 's_server', '-accept', (string) $port, '-cert', "$dir/c.pem", '-key', "$dir/k.pem", '-www', '-quiet'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        set_error_handler(static fn() => true);
        for ($try = 0; $try < 50 && !($connection = fsockopen('127.0.0.1', $port)); ++$try) {
            usleep(100_000);
        }
        restore_error_handler();
        if (!empty($connection)) {
            fclose($connection);
        }

        $groups = OpenSim_Health::answers([['title' => 'One', 'rows' => [['label' => 'tls', 'url' => "https://127.0.0.1:$port/", 'method' => 'get']]]]);
        proc_terminate($process);
        $row = $groups[0]['rows'][0];

        expect($code)->toBe(0)->and($row)->toMatchArray(['status' => '200', 'level' => 'ok', 'cert' => true])->not->toHaveKey('note');
    })->skip(fn() => trim((string) shell_exec('command -v openssl 2>/dev/null')) === '', 'no openssl to make a certificate');
});

describe('The sections of a grid', function () {
    /** What a grid says, as the ini files give it. */
    $in = fn(array $sections, array $more = []) => ['sources' => ['Robust.HG.ini' => $sections]] + $more;
    $by = fn(array $groups) => array_column(array_map(fn($g) => ['title' => $g['title'], 'rows' => array_column($g['rows'], null, 'label')], $groups), 'rows', 'title');

    test('are by file, named as the ini files do, every address of [GridInfoService] in them, the expected ones first', function () use ($in, $by) {
        $groups = OpenSim_Health::grid(
            $in(
                [
                    'GridInfoService' => [
                        'gridname' => 'Alpha',
                        'login' => 'http://play.example.org:8002/',
                        'economy' => 'https://play.example.org/helpers',
                        'web_profile_url' => 'https://play.example.org/profile/[AGENT_NAME]',
                        'partner' => 'https://partner.example.org/',
                    ],
                    'LoginService' => ['DestinationGuide' => 'https://play.example.org/helpers/guide.php'],
                    'DataSnapshot' => ['DATA_SRV_Alpha' => 'https://play.example.org/helpers/register.php'],
                ],
                ['login' => 'http://play.example.org:8002/'],
            ),
        );
        $rows = $by($groups);

        expect(array_map(fn($g) => $g['file'] . ' ' . $g['title'], $groups))
            ->toBe(['Robust.HG.ini [GridInfoService]', 'Robust.HG.ini [LoginService]', 'OpenSim.ini [Search]', 'OpenSim.ini [DataSnapshot]', 'OpenSim.ini [Messaging]', 'MoneyServer.ini [MoneyServer]'])
            // The ones of Robust.HG.ini.example, not what the example does not have (uas is the one of a standalone)
            ->and(array_keys($rows['[GridInfoService]']))
            ->toBe(['login', 'gatekeeper', 'welcome', 'economy', 'about', 'register', 'help', 'password', 'GridStatus', 'GridStatusRSS', 'web_profile_url', 'SearchURL', 'partner'])
            ->and($rows['[GridInfoService]']['partner']['url'])
            ->toBe('https://partner.example.org/')
            ->and($rows['[GridInfoService]']['about']['url'])
            ->toBe('')
            ->and(array_keys($rows['[LoginService]']))
            ->toBe(['DestinationGuide', 'MessageUrl'])
            ->and(array_keys($rows['[DataSnapshot]']))
            ->toBe(['DATA_SRV_Alpha']);
    });

    test('are in the file of Robust the grid has, whichever it is', function () use ($by) {
        $file = fn(array $sources) => OpenSim_Health::grid(['sources' => $sources])[0]['file'];

        expect($file(['Robust.ini' => []]))
            ->toBe('Robust.ini')
            ->and($file(['sims/one.ini' => [], 'Robust.HG.ini' => []]))
            ->toBe('Robust.HG.ini')
            ->and($file([]))
            ->toBe('Robust.ini');
    });

    test('tell the two SearchURL apart, the web page in Robust and the service of OpenSim, each in its own section and asked its own way', function () use ($in) {
        $groups = OpenSim_Health::grid($in(['GridInfoService' => ['SearchURL' => 'https://play.example.org/search/'], 'Search' => ['SearchURL' => 'https://play.example.org/helpers/query.php']]));
        $search = fn($title) => array_values(array_filter(array_merge(...array_column(array_filter($groups, fn($g) => $g['title'] === $title), 'rows')), fn($row) => $row['label'] === 'SearchURL'));
        $web = $search('[GridInfoService]');
        $api = $search('[Search]');

        // One row each: a page that is read, a call that makes sense that is answered
        expect($web)
            ->toHaveCount(1)
            ->and($web[0])
            ->toMatchArray(['url' => 'https://play.example.org/search/', 'tag' => 'Web search', 'method' => 'get'])
            ->and($api)
            ->toHaveCount(1)
            ->and($api[0])
            ->toMatchArray(['url' => 'https://play.example.org/helpers/query.php', 'tag' => 'API', 'method' => 'xmlrpc'])
            ->and($api[0]['body'])
            ->toContain('<methodName>dir_places_query</methodName>')
            ->and(array_column(array_filter($groups, fn($g) => $g['title'] === '[Search]'), 'file'))
            ->toBe(['OpenSim.ini']);
    });

    test('ask the grid info for the addresses of the grid, and expect a teapot where the viewers only add a name', function () use ($in, $by) {
        $rows = $by(OpenSim_Health::grid($in(['GridInfoService' => ['economy' => 'https://play.example.org/helpers']], ['login' => 'http://play.example.org:8002/'])))['[GridInfoService]'];

        expect($rows['login'])
            ->toMatchArray(['url' => 'http://play.example.org:8002/', 'probe' => 'http://play.example.org:8002/get_grid_info'])
            ->and($rows['economy']['expect'])
            ->toBe([418]);
    });

    test('ask the profile with the name of an avatar of the grid, and without any name, which is not a page (a teapot)', function () use ($in) {
        $info = ['GridInfoService' => ['web_profile_url' => 'https://play.example.org/profile/[AGENT_NAME]']];
        $profile = fn(array $groups) => array_values(array_filter($groups[0]['rows'], fn($row) => $row['label'] === 'web_profile_url'));
        $with = $profile(OpenSim_Health::grid($in($info, ['owner' => 'Jane.Doe'])));

        expect($with)
            ->toHaveCount(2)
            ->and($with[0])
            ->toMatchArray(['url' => 'https://play.example.org/profile/Jane.Doe'])
            ->not->toHaveKeys(['skip', 'expect'])
            ->and($with[1])
            ->toMatchArray(['url' => 'https://play.example.org/profile', 'expect' => [418]]);

        // No account to read: the one with a name is not asked
        $none = $profile(OpenSim_Health::grid($in($info)));

        expect($none[0])->toHaveKey('skip')->and($none[1]['url'])->toBe('https://play.example.org/profile');
    });

    test('say whether the grid has the key of its currency, never what it is, and an error for a grid with a currency that has none', function () use ($in, $by) {
        $row = fn(array $sections) => $by(OpenSim_Health::grid($in($sections)))['[MoneyServer]']['MoneyScriptAccessKey'];
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
    });
});

describe('What an install needs', function () {
    test('is said one row per thing: the extensions of PHP, the files it reads, a path that is not defined', function () {
        $dir = sys_get_temp_dir() . '/health-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/Robust.ini", '');
        $extensions = array_column(OpenSim_Health::extensions(), null, 'label');

        expect($extensions['xmlrpc']['level'])
            ->toBeIn(['ok', 'error'])
            ->and(OpenSim_Health::files(["$dir/Robust.ini", "$dir/absent.ini"]))
            ->toBe([OpenSim_Health::row('Robust.ini', true, "$dir/Robust.ini")])
            ->and(OpenSim_Health::path('GuideSource', ''))
            ->toMatchArray(['label' => 'GuideSource', 'status' => '', 'level' => 'none'])
            ->and(OpenSim_Health::path('GuideSource', "$dir/Robust.ini")['level'])
            ->toBe('ok');
    });
});
