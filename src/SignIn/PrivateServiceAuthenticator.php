<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

use OpenSim\Network\Http;

/**
 * Signs an avatar in with the user account and authentication services of the Robust of its grid, on its private port: the
 * account is looked up by its name, then the password is checked for it. Nothing is opened in the world and no database is read,
 * so it works for a grid on another machine as long as the manager reaches that port (the one the simulators of the grid use).
 *
 * The private port belongs to the machines of the grid: it is not to be opened to the Internet, and the password goes there as
 * the service expects it (its MD5, as the viewers send it), so a remote grid wants an https address (PrivURL), or a tunnel.
 */
final class PrivateServiceAuthenticator implements AvatarAuthenticator
{
    private const NO_SCOPE = '00000000-0000-0000-0000-000000000000';

    /** @var \Closure(string,array<string,scalar>):?string */
    private \Closure $post;

    /**
     * @param string $url the private address of the Robust, http://host:port
     * @param ?\Closure(string,array<string,scalar>):?string $post sends a form, gives the answer or null (default: HTTP)
     */
    public function __construct(private readonly string $url, ?\Closure $post = null)
    {
        $this->post = $post ?? static fn(string $url, array $fields): ?string => Http::post($url, $fields);
    }

    public function authenticate(string $name, string $password): SignInResult
    {
        $avatar = AvatarName::parse($name);
        if ($avatar === null || $password === '') {
            return SignInResult::refused();
        }
        try {
            $account = $this->account($avatar);
            if ($account === null) {
                return SignInResult::refused();
            }
            $token = $this->token($account['PrincipalID'], $password);
        } catch (\RuntimeException $e) {
            return SignInResult::unavailable($e->getMessage());
        }
        if ($token === null) {
            return SignInResult::refused();
        }
        // The token is of no use here: the check is all that was asked
        $this->release($account['PrincipalID'], $token);

        return SignInResult::granted(new Avatar(
            id: $account['PrincipalID'],
            firstName: $account['FirstName'],
            lastName: $account['LastName'],
            email: $account['Email'] ?? '',
            level: (int) ($account['UserLevel'] ?? 0),
            flags: (int) ($account['UserFlags'] ?? 0),
            title: $account['UserTitle'] ?? '',
        ));
    }

    public function opensSession(): bool
    {
        return false;
    }

    /**
     * The account of an avatar name.
     *
     * @return ?array<string,string> null when the grid has no such avatar
     * @throws \RuntimeException when the grid does not answer as it should
     */
    private function account(AvatarName $name): ?array
    {
        $xml = $this->ask('/accounts', [
            'METHOD' => 'getaccount',
            'ScopeID' => self::NO_SCOPE,
            'FirstName' => $name->first,
            'LastName' => $name->last,
        ]);
        if (!isset($xml->result)) {
            throw new \RuntimeException(_('The user account service of the grid answered nothing usable'));
        }
        $result = $xml->result;
        if (!isset($result->PrincipalID)) {
            return null;
        }
        $account = [];
        foreach ($result->children() as $key => $value) {
            $account[(string) $key] = (string) $value;
        }

        return isset($account['FirstName'], $account['LastName']) ? $account : null;
    }

    /**
     * @return ?string the token of the grid when the password is right
     * @throws \RuntimeException when the grid does not answer as it should
     */
    private function token(string $principal, string $password): ?string
    {
        $xml = $this->ask('/auth/plain', [
            'METHOD' => 'authenticate',
            'PRINCIPAL' => $principal,
            // What the service compares: the MD5 of the password, as the viewers send it
            'PASSWORD' => md5($password),
            'LIFETIME' => 30,
        ]);
        $result = (string) ($xml->Result ?? '');
        if ($result === 'Success' && (string) ($xml->Token ?? '') !== '') {
            return (string) $xml->Token;
        }
        if ($result === 'Failure') {
            return null;
        }

        throw new \RuntimeException(_('The authentication service of the grid answered nothing usable'));
    }

    private function release(string $principal, string $token): void
    {
        try {
            $this->ask('/auth/plain', ['METHOD' => 'release', 'PRINCIPAL' => $principal, 'TOKEN' => $token]);
        } catch (\RuntimeException) {
            // It expires by itself
        }
    }

    /**
     * @param array<string,scalar> $fields
     * @throws \RuntimeException when there is no answer, or one that is not XML
     */
    private function ask(string $path, array $fields): \SimpleXMLElement
    {
        $body = ($this->post)(rtrim($this->url, '/') . $path, $fields);
        if ($body === null || $body === '') {
            throw new \RuntimeException(sprintf(_('The grid does not answer on %s'), $this->url));
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new \RuntimeException(sprintf(_('The grid answers on %s, but not as a Robust'), $this->url));
        }

        return $xml;
    }
}
