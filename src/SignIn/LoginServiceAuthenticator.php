<?php

declare(strict_types=1);

namespace OpenSim\SignIn;

use OpenSim\Network\Http;
use PhpXmlRpc\Encoder;
use PhpXmlRpc\Request;
use PhpXmlRpc\Response;

/**
 * Signs an avatar in with the login service of its grid on the public port, the one the viewers use (XML-RPC login_to_simulator).
 * It works for any grid that can be reached, with nothing but its login address.
 *
 * Unlike the others, a success is a login in the world: the grid starts a session of the avatar and gives the circuit of a region
 * (in `details`), which stays until the avatar is logged out. That is not what a sign-in for a web page is for (see opensSession()),
 * but it is what a feature that sends an avatar somewhere needs. When the grid refuses for another reason than the avatar (no
 * region to log into, a login already open...), the sign-in is unavailable: the password could not be judged.
 */
final class LoginServiceAuthenticator implements AvatarAuthenticator
{
    /** What the grid says when it does not know the avatar or does not accept its password, said alike. */
    private const NOT_AUTHENTICATED = 'Could not authenticate your avatar';

    /** @var \Closure(string,string):?string */
    private \Closure $post;

    /**
     * @param string $url the login address of the grid, the one its grid info gives (http://host:port/)
     * @param string $start where the avatar logs in: last, home, or uri:Region&x&y&z
     * @param ?\Closure(string,string):?string $post sends an XML-RPC call, gives the answer or null (default: HTTP)
     */
    public function __construct(
        private readonly string $url,
        private readonly string $start = 'last',
        private readonly string $channel = 'opensim-engine',
        ?\Closure $post = null,
    ) {
        $this->post = $post ?? static fn(string $url, string $call): ?string => Http::post($url, $call, ['Content-Type: text/xml']);
    }

    public function authenticate(string $name, string $password): SignInResult
    {
        $avatar = AvatarName::parse($name);
        if ($avatar === null || $password === '') {
            return SignInResult::refused();
        }
        $encoder = new Encoder();
        $call = (new Request('login_to_simulator', [$encoder->encode([
            'first' => $avatar->first,
            'last' => $avatar->last,
            // As the viewers send it: the MD5 of the password
            'passwd' => '$1$' . md5($password),
            'start' => $this->start,
            'channel' => $this->channel,
            'version' => '1.0',
            'platform' => PHP_OS_FAMILY,
            'mac' => '',
            'id0' => '',
            'agree_to_tos' => 'true',
            'read_critical' => 'true',
            'viewer_digest' => '',
            'options' => [],
        ])]))->serialize();

        $body = ($this->post)($this->url, $call);
        if ($body === null || $body === '') {
            return SignInResult::unavailable(sprintf(_('The login service does not answer on %s'), $this->url));
        }
        $answer = str_contains($body, '<methodResponse') ? @$encoder->decodeXml($body) : false;
        if (!$answer instanceof Response || $answer->faultCode() !== 0) {
            return SignInResult::unavailable(sprintf(_('The login service answers on %s, but not as a grid'), $this->url));
        }
        $response = $encoder->decode($answer->value());
        if (!is_array($response) || !isset($response['login'])) {
            return SignInResult::unavailable(sprintf(_('The login service answers on %s, but not as a grid'), $this->url));
        }

        if (($response['login'] ?? '') === 'true' && ($response['agent_id'] ?? '') !== '') {
            return SignInResult::granted(new Avatar(
                id: (string) $response['agent_id'],
                firstName: trim((string) ($response['first_name'] ?? $avatar->first), '"'),
                lastName: trim((string) ($response['last_name'] ?? $avatar->last), '"'),
            ), $response);
        }
        $message = (string) ($response['message'] ?? '');
        if (str_starts_with($message, self::NOT_AUTHENTICATED)) {
            return SignInResult::refused();
        }

        return SignInResult::unavailable(sprintf(_('The grid did not log the avatar in: %s'), $message !== '' ? $message : (string) ($response['reason'] ?? $response['login'])));
    }

    public function opensSession(): bool
    {
        return true;
    }
}
