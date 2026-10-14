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
