<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

use OpenSim\Network\Http;

/**
 * Signs an avatar in with the OpenID 2.0 provider of the Robust of its grid (`OpenIdServerConnector`, public port): the grid asks
 * the password on its own page and the application never sees it. The assertion is verified with the grid (check_authentication).
 *
 * The avatar of a granted result has no id; `details['response_nonce']` is the nonce of the assertion.
 */
final class OpenIdAuthenticator implements RedirectAuthenticator
{
    private const NS = 'http://specs.openid.net/auth/2.0';

    /** @var \Closure(string):?string */
    private \Closure $get;

    /** @var \Closure(string,array<string,scalar>):?string */
    private \Closure $post;

    /**
     * @param string $url the public address of the grid, http://host:port
     * @param ?\Closure(string):?string $get fetches a page, gives it or null (default: HTTP)
     * @param ?\Closure(string,array<string,scalar>):?string $post sends a form, gives the answer or null (default: HTTP)
     */
    public function __construct(private readonly string $url, ?\Closure $get = null, ?\Closure $post = null)
    {
        $this->get = $get ?? static fn(string $url): ?string => Http::get($url);
        $this->post = $post ?? static fn(string $url, array $fields): ?string => Http::post($url, $fields);
    }

    public function begin(string $name, string $returnTo): SignInRedirect|SignInResult
    {
        $avatar = AvatarName::parse($name);
        // The identity URL is First_Last
        if ($avatar === null || $avatar->grid !== null || str_contains($avatar->first . $avatar->last, '_')) {
            return SignInResult::refused();
        }
        $identity = rtrim($this->url, '/') . '/users/' . rawurlencode($avatar->first . '_' . $avatar->last);
        $page = ($this->get)($identity);
        if ($page === null || $page === '') {
            return SignInResult::unavailable(sprintf(_('The grid does not answer on %s'), $this->url));
        }
        if (str_contains($page, 'Invalid OpenID identity')) {
            return SignInResult::refused();
        }
        if (preg_match('/<link\s+rel="openid2\.provider[^"]*"\s+href="([^"]+)"/i', $page, $link) !== 1) {
            return SignInResult::unavailable(
                sprintf(_('The grid answers on %s, but has no OpenID provider'), $this->url),
            );
        }
        $endpoint = html_entity_decode($link[1]);
        $parts = parse_url($returnTo);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return SignInResult::refused();
        }
        $realm = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';

        $url =
            $endpoint .
            (str_contains($endpoint, '?') ? '&' : '?') .
            http_build_query([
                'openid.ns' => self::NS,
                'openid.mode' => 'checkid_setup',
                'openid.claimed_id' => $identity,
                'openid.identity' => $identity,
                'openid.return_to' => $returnTo,
                'openid.realm' => $realm,
            ]);

        return new SignInRedirect($url, [
            'identity' => $identity,
            'endpoint' => $endpoint,
            'return_to' => $returnTo,
            'first_name' => $avatar->first,
            'last_name' => $avatar->last,
        ]);
    }

    public function complete(SignInRedirect $redirect, string $queryString): SignInResult
    {
        $state = $redirect->state;
        $response = self::parse($queryString);
        if (($response['openid.mode'] ?? '') !== 'id_res') {
            return SignInResult::refused();
        }
        $signed = explode(',', $response['openid.signed'] ?? '');
        if (
            ($response['openid.ns'] ?? '') !== self::NS ||
            ($response['openid.return_to'] ?? '') !== ($state['return_to'] ?? null) ||
            ($response['openid.claimed_id'] ?? '') !== ($state['identity'] ?? null) ||
            ($response['openid.identity'] ?? '') !== ($state['identity'] ?? null) ||
            (isset($response['openid.op_endpoint']) &&
                $response['openid.op_endpoint'] !== ($state['endpoint'] ?? null)) ||
            ($response['openid.response_nonce'] ?? '') === '' ||
            array_diff(['return_to', 'response_nonce', 'claimed_id', 'identity'], $signed) !== []
        ) {
            return SignInResult::refused();
        }

        $verify = $response;
        $verify['openid.mode'] = 'check_authentication';
        $answer = ($this->post)($state['endpoint'], $verify);
        if ($answer === null) {
            return SignInResult::unavailable(sprintf(_('The grid does not answer on %s'), $this->url));
        }
        if (preg_match('/^is_valid:true\s*$/m', $answer) !== 1) {
            return SignInResult::refused();
        }

        return SignInResult::granted(
            new Avatar(id: '', firstName: $state['first_name'] ?? '', lastName: $state['last_name'] ?? ''),
            ['response_nonce' => $response['openid.response_nonce']],
        );
    }

    /**
     * The openid.* fields of a raw query string, with their dots.
     *
     * @return array<string,string>
     */
    private static function parse(string $queryString): array
    {
        $fields = [];
        foreach (explode('&', ltrim($queryString, '?')) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $key = urldecode($key);
            if (str_starts_with($key, 'openid.')) {
                $fields[$key] = urldecode($value);
            }
        }

        return $fields;
    }
}
