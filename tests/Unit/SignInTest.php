<?php

declare(strict_types=1);

/**
 * Signing an avatar in with the services of its grid: the name as a person writes it, and the private port of Robust.
 */

use OpenSim\SignIn\AvatarName;
use OpenSim\SignIn\PrivateServiceAuthenticator;
use OpenSim\SignIn\SignInResult;

const SIGNIN_ID = '11111111-2222-3333-4444-555555555555';

/** The answer of a Robust to getaccount for an avatar, and the one for an avatar it does not have. */
function signin_account(string $first = 'Gudule', string $last = 'Lapointe'): string
{
    return '<?xml version="1.0"?><ServerResponse><result type="List"><FirstName>' . $first . '</FirstName><LastName>' . $last . '</LastName>'
        . '<Email>gudule@example.org</Email><PrincipalID>' . SIGNIN_ID . '</PrincipalID><ScopeID>00000000-0000-0000-0000-000000000000</ScopeID>'
        . '<Created>1700000000</Created><UserLevel>200</UserLevel><UserFlags>0</UserFlags><UserTitle></UserTitle><UserCountry /><LocalToGrid>True</LocalToGrid>'
        . '<ServiceURLs></ServiceURLs></result></ServerResponse>';
}

const SIGNIN_NO_ACCOUNT = '<?xml version="1.0"?><ServerResponse><result>null</result></ServerResponse>';
const SIGNIN_SUCCESS = '<?xml version="1.0"?><ServerResponse><Result>Success</Result><Token>9c2b5e1a-0000-4000-8000-000000000001</Token></ServerResponse>';
const SIGNIN_FAILURE = '<?xml version="1.0"?><ServerResponse><Result>Failure</Result></ServerResponse>';

/**
 * A grid that answers each path with what it is given (a closure for one that depends on the form), and keeps the requests.
 *
 * @param array<string,string|Closure|null> $answers
 * @param list<array{0:string,1:array}> $requests
 */
function signin_grid(array $answers, array &$requests = []): Closure
{
    return function (string $url, array $fields) use ($answers, &$requests): ?string {
        $requests[] = [$url, $fields];
        $answer = $answers[parse_url($url, PHP_URL_PATH)] ?? null;

        return $answer instanceof Closure ? $answer($fields) : $answer;
    };
}

describe('The name of an avatar', function () {
    test('is a first and a last name, separated by a space or a dot', function () {
        expect(AvatarName::parse('Gudule Lapointe')->full())->toBe('Gudule Lapointe')
            ->and(AvatarName::parse('  Gudule   Lapointe ')->full())->toBe('Gudule Lapointe')
            ->and(AvatarName::parse('gudule.lapointe')->full())->toBe('gudule lapointe');
    });

    test('has Resident for last name when it has none', function () {
        expect(AvatarName::parse('Gudule')->full())->toBe('Gudule Resident');
    });

    test('may name the grid of the avatar, the hypergrid way', function () {
        $name = AvatarName::parse('Gudule Lapointe@grid.example.org:8002');

        expect($name->full())->toBe('Gudule Lapointe')
            ->and($name->grid)->toBe('grid.example.org:8002');
    });

    test('is not an empty text, three words, an empty grid or a control character', function () {
        foreach (['', '   ', 'One Two Three', 'Gudule Lapointe@', "Gudule\x01 Lapointe", 'Gudule Lapointe@bad host'] as $text) {
            expect(AvatarName::parse($text))->toBeNull($text);
        }
    });
});

describe('The private port of a Robust', function () {
    test('grants the avatar whose password it accepts, as the grid knows it', function () {
        $signIn = new PrivateServiceAuthenticator('http://grid.test:8003', signin_grid(['/accounts' => signin_account('Gudule', 'Lapointe'), '/auth/plain' => SIGNIN_SUCCESS]));

        $result = $signIn->authenticate('gudule lapointe', 'secret');

        expect($result->status)->toBe(SignInResult::GRANTED)
            ->and($result->isGranted())->toBeTrue()
            ->and($result->avatar->id)->toBe(SIGNIN_ID)
            ->and($result->avatar->fullName())->toBe('Gudule Lapointe')
            ->and($result->avatar->email)->toBe('gudule@example.org')
            ->and($result->avatar->level)->toBe(200);
    });

    test('asks for the account by its name, then checks the MD5 of the password for it, and gives the token back', function () {
        $requests = [];
        (new PrivateServiceAuthenticator('http://grid.test:8003/', signin_grid(['/accounts' => signin_account(), '/auth/plain' => SIGNIN_SUCCESS], $requests)))
            ->authenticate('Gudule Lapointe', 'secret');

        [$account, $check, $release] = $requests;

        expect($account[0])->toBe('http://grid.test:8003/accounts')
            ->and($account[1])->toMatchArray(['METHOD' => 'getaccount', 'FirstName' => 'Gudule', 'LastName' => 'Lapointe'])
            ->and($check[0])->toBe('http://grid.test:8003/auth/plain')
            ->and($check[1])->toMatchArray(['METHOD' => 'authenticate', 'PRINCIPAL' => SIGNIN_ID, 'PASSWORD' => md5('secret')])
            ->and($release[1])->toMatchArray(['METHOD' => 'release', 'PRINCIPAL' => SIGNIN_ID, 'TOKEN' => '9c2b5e1a-0000-4000-8000-000000000001'])
            ->and(json_encode($requests))->not->toContain('"secret"');
    });

    test('refuses a wrong password', function () {
        $signIn = new PrivateServiceAuthenticator('http://grid.test:8003', signin_grid(['/accounts' => signin_account(), '/auth/plain' => SIGNIN_FAILURE]));

        expect($signIn->authenticate('Gudule Lapointe', 'wrong')->status)->toBe(SignInResult::REFUSED);
    });

    test('refuses an avatar it does not have, without asking for a password', function () {
        $requests = [];
        $signIn = new PrivateServiceAuthenticator('http://grid.test:8003', signin_grid(['/accounts' => SIGNIN_NO_ACCOUNT], $requests));

        expect($signIn->authenticate('Nobody Nowhere', 'secret')->status)->toBe(SignInResult::REFUSED)
            ->and($requests)->toHaveCount(1);
    });

    test('refuses a name that is not one and an empty password, without asking the grid', function () {
        $requests = [];
        $signIn = new PrivateServiceAuthenticator('http://grid.test:8003', signin_grid([], $requests));

        expect($signIn->authenticate('', 'secret')->status)->toBe(SignInResult::REFUSED)
            ->and($signIn->authenticate('Gudule Lapointe', '')->status)->toBe(SignInResult::REFUSED)
            ->and($requests)->toBe([]);
    });

    test('says the grid is unavailable when it does not answer, or not as a Robust', function () {
        foreach ([[], ['/accounts' => ''], ['/accounts' => '<html>not xml'], ['/accounts' => '<ServerResponse />'], ['/accounts' => signin_account()], ['/accounts' => signin_account(), '/auth/plain' => '<ServerResponse />']] as $answers) {
            $result = (new PrivateServiceAuthenticator('http://grid.test:8003', signin_grid($answers)))->authenticate('Gudule Lapointe', 'secret');

            expect($result->status)->toBe(SignInResult::UNAVAILABLE)
                ->and($result->reason)->not->toBe('');
        }
    });

    test('opens no session in the world', function () {
        expect((new PrivateServiceAuthenticator('http://grid.test:8003'))->opensSession())->toBeFalse();
    });
});

/** What a login service answers: the struct of a login_to_simulator response, as XML-RPC. */
function signin_login_answer(array $struct): string
{
    return (new PhpXmlRpc\Response((new PhpXmlRpc\Encoder())->encode($struct)))->serialize();
}

describe('The login service of a grid', function () {
    test('grants the avatar it logs in, and gives the session it opened', function () {
        $answer = signin_login_answer(['login' => 'true', 'agent_id' => SIGNIN_ID, 'first_name' => '"Gudule"', 'last_name' => '"Lapointe"', 'session_id' => 'abc', 'sim_ip' => '127.0.0.1']);
        $signIn = new OpenSim\SignIn\LoginServiceAuthenticator('http://grid.test:8002/', 'last', 'test', fn(string $url, string $call): string => $answer);

        $result = $signIn->authenticate('gudule lapointe', 'secret');

        expect($result->status)->toBe(SignInResult::GRANTED)
            ->and($result->avatar->id)->toBe(SIGNIN_ID)
            ->and($result->avatar->fullName())->toBe('Gudule Lapointe')
            ->and($result->details)->toMatchArray(['session_id' => 'abc', 'sim_ip' => '127.0.0.1'])
            ->and($signIn->opensSession())->toBeTrue();
    });

    test('calls login_to_simulator as a viewer does, with the MD5 of the password', function () {
        $sent = [];
        $signIn = new OpenSim\SignIn\LoginServiceAuthenticator('http://grid.test:8002/', 'home', 'test', function (string $url, string $call) use (&$sent): string {
            $sent = [$url, $call];

            return signin_login_answer(['login' => 'false', 'reason' => 'key', 'message' => 'Could not authenticate your avatar. Please check your username and password, and check the grid if problems persist.']);
        });
        $signIn->authenticate('Gudule Lapointe', 'secret');

        $encoder = new PhpXmlRpc\Encoder();
        $request = $encoder->decodeXml($sent[1]);
        $fields = $encoder->decode($request->getParam(0));

        expect($sent[0])->toBe('http://grid.test:8002/')
            ->and($request->method())->toBe('login_to_simulator')
            ->and($fields)->toMatchArray(['first' => 'Gudule', 'last' => 'Lapointe', 'passwd' => '$1$' . md5('secret'), 'start' => 'home', 'channel' => 'test'])
            ->and($sent[1])->not->toContain('secret');
    });

    test('refuses an avatar it does not authenticate', function () {
        $answer = signin_login_answer(['login' => 'false', 'reason' => 'key', 'message' => 'Could not authenticate your avatar. Please check your username and password, and check the grid if problems persist.']);
        $signIn = new OpenSim\SignIn\LoginServiceAuthenticator('http://grid.test:8002/', post: fn(string $url, string $call): string => $answer);

        expect($signIn->authenticate('Gudule Lapointe', 'wrong')->status)->toBe(SignInResult::REFUSED);
    });

    test('cannot judge the password when the grid refuses for another reason', function () {
        $answer = signin_login_answer(['login' => 'false', 'reason' => 'key', 'message' => 'Error connecting to the desired location. Try connecting to another region.']);
        $result = (new OpenSim\SignIn\LoginServiceAuthenticator('http://grid.test:8002/', post: fn(string $url, string $call): string => $answer))->authenticate('Gudule Lapointe', 'secret');

        expect($result->status)->toBe(SignInResult::UNAVAILABLE)
            ->and($result->reason)->toContain('Error connecting to the desired location');
    });

    test('says the grid is unavailable when it does not answer, or not as a grid', function () {
        $fault = (new PhpXmlRpc\Response(0, 1, 'no such method'))->serialize();
        foreach ([null, '', '<html>not xml', $fault, signin_login_answer(['hello' => 'world'])] as $answer) {
            $signIn = new OpenSim\SignIn\LoginServiceAuthenticator('http://grid.test:8002/', post: fn(string $url, string $call): ?string => $answer);

            expect($signIn->authenticate('Gudule Lapointe', 'secret')->status)->toBe(SignInResult::UNAVAILABLE);
        }
    });

    test('refuses a name that is not one and an empty password, without asking the grid', function () {
        $asked = false;
        $signIn = new OpenSim\SignIn\LoginServiceAuthenticator('http://grid.test:8002/', post: function () use (&$asked): ?string {
            $asked = true;

            return null;
        });

        expect($signIn->authenticate('', 'secret')->status)->toBe(SignInResult::REFUSED)
            ->and($signIn->authenticate('Gudule Lapointe', '')->status)->toBe(SignInResult::REFUSED)
            ->and($asked)->toBeFalse();
    });
});

const SIGNIN_OPENID_PAGE = '<html><head><title>Gudule Lapointe</title><link rel="openid2.provider openid.server" href="http://grid.test:8002/openid/server/"/></head><body>OpenID identifier for Gudule Lapointe</body></html>';
const SIGNIN_OPENID_RETURN = 'https://manager.test/login/grid?token=abc';

/** An OpenID authenticator of a grid that serves its identity pages, and the requests it was asked. */
function signin_openid(array &$posts = [], ?string $verification = "ns:http://specs.openid.net/auth/2.0\nis_valid:true\n"): OpenSim\SignIn\OpenIdAuthenticator
{
    return new OpenSim\SignIn\OpenIdAuthenticator(
        'http://grid.test:8002/',
        get: fn(string $url): ?string => $url === 'http://grid.test:8002/users/Gudule_Lapointe' ? SIGNIN_OPENID_PAGE : '<html><body>Invalid OpenID identity</body></html>',
        post: function (string $url, array $fields) use (&$posts, $verification): ?string {
            $posts[] = [$url, $fields];

            return $verification;
        },
    );
}

/** What the grid sends the person back with when the password was right, for the redirect that was made. */
function signin_openid_assertion(OpenSim\SignIn\SignInRedirect $redirect, array $changes = []): string
{
    $fields = [
        'openid.ns' => 'http://specs.openid.net/auth/2.0',
        'openid.mode' => 'id_res',
        'openid.op_endpoint' => $redirect->state['endpoint'],
        'openid.claimed_id' => $redirect->state['identity'],
        'openid.identity' => $redirect->state['identity'],
        'openid.return_to' => $redirect->state['return_to'],
        'openid.response_nonce' => '2026-10-14T00:00:00Zk3j2',
        'openid.assoc_handle' => 'handle',
        'openid.signed' => 'op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'openid.sig' => 'c2ln',
    ];

    return http_build_query(array_filter($changes + $fields, fn($value) => $value !== null));
}

describe('The OpenID provider of a grid', function () {
    test('sends the person to the grid to give their password there', function () {
        $redirect = signin_openid()->begin('Gudule Lapointe', SIGNIN_OPENID_RETURN);

        expect($redirect)->toBeInstanceOf(OpenSim\SignIn\SignInRedirect::class);
        parse_str((string) parse_url($redirect->url, PHP_URL_QUERY), $query);

        expect($redirect->url)->toStartWith('http://grid.test:8002/openid/server/?')
            ->and($query['openid_mode'])->toBe('checkid_setup')
            ->and($query['openid_claimed_id'])->toBe('http://grid.test:8002/users/Gudule_Lapointe')
            ->and($query['openid_identity'])->toBe('http://grid.test:8002/users/Gudule_Lapointe')
            ->and($query['openid_return_to'])->toBe(SIGNIN_OPENID_RETURN)
            ->and($query['openid_realm'])->toBe('https://manager.test/')
            ->and($redirect->state)->toMatchArray(['endpoint' => 'http://grid.test:8002/openid/server/', 'return_to' => SIGNIN_OPENID_RETURN]);
    });

    test('refuses an avatar the grid does not have, a name it cannot write as First_Last, and an avatar of another grid', function () {
        foreach (['Nobody Nowhere', 'Gu_dule Lapointe', 'Gudule Lapointe@other.example.org', ''] as $name) {
            $result = signin_openid()->begin($name, SIGNIN_OPENID_RETURN);

            expect($result)->toBeInstanceOf(SignInResult::class)
                ->and($result->status)->toBe(SignInResult::REFUSED, $name);
        }
    });

    test('says the grid is unavailable when it does not answer, or has no OpenID provider', function () {
        $silent = new OpenSim\SignIn\OpenIdAuthenticator('http://grid.test:8002', get: fn(string $url): ?string => null);
        $nothing = new OpenSim\SignIn\OpenIdAuthenticator('http://grid.test:8002', get: fn(string $url): ?string => '<html><body>Hello</body></html>');

        expect($silent->begin('Gudule Lapointe', SIGNIN_OPENID_RETURN)->status)->toBe(SignInResult::UNAVAILABLE)
            ->and($nothing->begin('Gudule Lapointe', SIGNIN_OPENID_RETURN)->status)->toBe(SignInResult::UNAVAILABLE);
    });

    test('grants the avatar when the grid itself says what it sent back is its own', function () {
        $posts = [];
        $signIn = signin_openid($posts);
        $redirect = $signIn->begin('Gudule Lapointe', SIGNIN_OPENID_RETURN);
        $assertion = signin_openid_assertion($redirect);

        $result = $signIn->complete(OpenSim\SignIn\SignInRedirect::resume($redirect->state), $assertion);

        expect($result->status)->toBe(SignInResult::GRANTED)
            ->and($result->avatar->fullName())->toBe('Gudule Lapointe')
            ->and($result->details['response_nonce'])->toBe('2026-10-14T00:00:00Zk3j2')
            ->and($posts)->toHaveCount(1)
            ->and($posts[0][0])->toBe('http://grid.test:8002/openid/server/')
            ->and($posts[0][1])->toMatchArray(['openid.mode' => 'check_authentication', 'openid.sig' => 'c2ln', 'openid.signed' => 'op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle']);
    });

    test('refuses what the grid does not confirm, and what the person cancelled', function () {
        $redirect = signin_openid()->begin('Gudule Lapointe', SIGNIN_OPENID_RETURN);
        $assertion = signin_openid_assertion($redirect);

        expect(signin_openid(verification: "is_valid:false\n")->complete($redirect, $assertion)->status)->toBe(SignInResult::REFUSED)
            ->and(signin_openid()->complete($redirect, 'openid.ns=' . urlencode('http://specs.openid.net/auth/2.0') . '&openid.mode=cancel')->status)->toBe(SignInResult::REFUSED)
            ->and(signin_openid()->complete($redirect, '')->status)->toBe(SignInResult::REFUSED);
    });

    test('refuses an answer that is not for this sign-in, without asking the grid', function () {
        $posts = [];
        $signIn = signin_openid($posts);
        $redirect = $signIn->begin('Gudule Lapointe', SIGNIN_OPENID_RETURN);
        $other = 'http://grid.test:8002/users/Someone_Else';

        foreach ([
            ['openid.claimed_id' => $other, 'openid.identity' => $other],
            ['openid.return_to' => 'https://evil.test/login'],
            ['openid.op_endpoint' => 'http://evil.test/openid/server/'],
            ['openid.response_nonce' => null],
            ['openid.signed' => 'op_endpoint,claimed_id,identity,assoc_handle'],
            ['openid.ns' => null],
        ] as $changes) {
            expect($signIn->complete($redirect, signin_openid_assertion($redirect, $changes))->status)->toBe(SignInResult::REFUSED, json_encode($changes));
        }
        expect($posts)->toBe([]);
    });

    test('reads the names of the fields as written, dots included', function () {
        $posts = [];
        $signIn = signin_openid($posts);
        $redirect = $signIn->begin('Gudule Lapointe', SIGNIN_OPENID_RETURN);

        // As the grid writes them in the address: dots in the names, escaped values
        expect($signIn->complete($redirect, '?' . signin_openid_assertion($redirect))->isGranted())->toBeTrue();
    });

    test('says the grid is unavailable when it cannot be asked to confirm', function () {
        $redirect = signin_openid()->begin('Gudule Lapointe', SIGNIN_OPENID_RETURN);
        $signIn = signin_openid(verification: null);

        expect($signIn->complete($redirect, signin_openid_assertion($redirect))->status)->toBe(SignInResult::UNAVAILABLE);
    });
});
