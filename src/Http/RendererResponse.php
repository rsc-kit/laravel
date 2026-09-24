<?php

namespace RscKit\Http;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The renderer's answer, with its cookies kept out of Laravel's hands.
 *
 * The proxy sits on the 'web' group, and it has to: the proxied page's
 * server actions post back through it, and CSRF verification needs the
 * session StartSession binds. But a Set-Cookie handed to a Response as a
 * header becomes a Cookie object, and every Cookie object on the way out is
 * EncryptCookies' to encrypt and StartSession's to replace. The renderer's
 * cookies were already produced by Laravel - a host call ran under the same
 * middleware, and its response is where they came from - so encrypting them
 * again gave the browser a value nothing could decrypt, and the proxy's own
 * session cookie, stamped over the host call's, put back the session a login
 * had just migrated away from. The visitor signed in and arrived signed out.
 *
 * So they are held here as the lines the renderer sent, and written after
 * everything the middleware stack added, when the headers go out. A cookie
 * the stack set under a name the renderer also set is dropped rather than
 * sent alongside: the renderer's answer is the later one, and two cookies of
 * one name leave the browser to pick.
 *
 * The narrower alternative, taking the session and encryption off the proxy
 * route, would take CSRF with them - the one thing the proxy's session is for.
 */
class RendererResponse extends StreamedResponse
{
    private bool $cookiesSent = false;

    /**
     * @param  list<string>  $rendererCookies  Set-Cookie values, byte for byte
     * @param  array<string, list<string>|string>  $headers
     */
    public function __construct(
        ?callable $callback,
        int $status,
        array $headers,
        private array $rendererCookies,
    ) {
        parent::__construct($callback, $status, $headers);
    }

    /**
     * Every Set-Cookie value this response will send, in order.
     *
     * What sendHeaders() writes, without writing it - the same answer a
     * browser would get, for anything that needs to know before then.
     *
     * @return list<string>
     */
    public function setCookieLines(): array
    {
        $ours = array_filter($this->headers->getCookies(), fn (Cookie $cookie) => ! $this->clobbers($cookie));

        return [...array_map('strval', array_values($ours)), ...$this->rendererCookies];
    }

    /** @return list<string> */
    public function rendererCookies(): array
    {
        return $this->rendererCookies;
    }

    public function sendHeaders(?int $statusCode = null): static
    {
        // An informational response - a 103 - carries no cookies, and the
        // real one that follows is where they belong.
        $final = $statusCode === null || $statusCode >= 200;
        $sending = $final && ! $this->cookiesSent && ! headers_sent();

        if ($final) {
            foreach ($this->headers->getCookies() as $cookie) {
                if ($this->clobbers($cookie)) {
                    $this->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
                }
            }
        }

        parent::sendHeaders($statusCode);

        if ($sending) {
            foreach ($this->rendererCookies as $line) {
                header('Set-Cookie: '.$line, false);
            }

            $this->cookiesSent = true;
        }

        return $this;
    }

    /** Whether the renderer set a cookie of this one's name. */
    private function clobbers(Cookie $cookie): bool
    {
        foreach ($this->rendererCookies as $line) {
            if (trim(explode('=', $line, 2)[0]) === $cookie->getName()) {
                return true;
            }
        }

        return false;
    }
}
