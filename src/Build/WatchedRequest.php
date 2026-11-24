<?php

namespace RscKit\Build;

use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * The request a build's call is answered under: the host-call endpoint's url,
 * and no visitor behind it.
 *
 * What a visitor would have sent - cookies, headers, an address - is noted
 * when it is read. The url is not: at run time the call is the renderer's, so
 * its host and path are the endpoint's whoever is asking.
 */
class WatchedRequest extends Request
{
    private ?Visitor $visitor = null;

    /** Where a PHP without lazy objects keeps the cookies, out of reach of the property. */
    private ?InputBag $cookiesHeld = null;

    public static function to(string $uri, Visitor $visitor): self
    {
        $request = self::create($uri, 'POST');
        $request->visitor = $visitor;

        $request->put('headers', new WatchedHeaders($visitor, ['Accept' => ['application/json']]));
        $request->put('server', new WatchedServer($visitor, array_diff_key($request->server->all(), [
            'REMOTE_ADDR' => true, 'HTTP_USER_AGENT' => true,
        ])));
        $request->put('attributes', new WatchedAttributes($visitor));
        $request->watchCookies();

        return $request;
    }

    /**
     * The cookie bag is final, so it cannot be subclassed the way the others
     * are. As a lazy object it is filled in the first time anything touches
     * it, which is the moment to note. Before PHP 8.4 there are none - and no
     * Symfony whose request has property hooks - so the property goes through
     * __get instead.
     */
    private function watchCookies(): void
    {
        if (PHP_VERSION_ID < 80400) {
            $this->cookiesHeld = new InputBag;

            unset($this->cookies);

            return;
        }

        $visitor = $this->visitor;

        $this->put('cookies', (new ReflectionClass(InputBag::class))->newLazyGhost(function (InputBag $bag) use ($visitor): void {
            $visitor?->read('a cookie');
            $bag->__construct();
        }));
    }

    /** Past Symfony's set hooks, which deprecate assigning a bag. */
    private function put(string $property, object $bag): void
    {
        if (PHP_VERSION_ID < 80400) {
            $this->{$property} = $bag;

            return;
        }

        (new ReflectionProperty(SymfonyRequest::class, $property))->setRawValue($this, $bag);
    }

    public function __get($key)
    {
        if ($key === 'cookies' && $this->cookiesHeld !== null) {
            $this->visitor?->read('a cookie');

            return $this->cookiesHeld;
        }

        return parent::__get($key);
    }

    public function ip()
    {
        $this->visitor?->read("the visitor's address");

        return null;
    }

    /** @return list<string> */
    public function ips()
    {
        $this->visitor?->read("the visitor's address");

        return [];
    }

    public function getClientIp(): ?string
    {
        $this->visitor?->read("the visitor's address");

        return null;
    }

    /** @return list<string> */
    public function getClientIps(): array
    {
        $this->visitor?->read("the visitor's address");

        return [];
    }

    // The endpoint's own url, and how the body is encoded: the same whoever
    // the page is for. url(), route() and input() are built on these.

    public function getHost(): string
    {
        return $this->notAsking(fn () => parent::getHost());
    }

    public function getScheme(): string
    {
        return $this->notAsking(fn () => parent::getScheme());
    }

    public function isSecure(): bool
    {
        return $this->notAsking(fn () => parent::isSecure());
    }

    public function getPort(): int|string|null
    {
        return $this->notAsking(fn () => parent::getPort());
    }

    public function getHttpHost(): string
    {
        return $this->notAsking(fn () => parent::getHttpHost());
    }

    public function getBaseUrl(): string
    {
        return $this->notAsking(fn () => parent::getBaseUrl());
    }

    public function getBasePath(): string
    {
        return $this->notAsking(fn () => parent::getBasePath());
    }

    public function getPathInfo(): string
    {
        return $this->notAsking(fn () => parent::getPathInfo());
    }

    public function getRequestUri(): string
    {
        return $this->notAsking(fn () => parent::getRequestUri());
    }

    public function getQueryString(): ?string
    {
        return $this->notAsking(fn () => parent::getQueryString());
    }

    public function getMethod(): string
    {
        return $this->notAsking(fn () => parent::getMethod());
    }

    public function getRealMethod(): string
    {
        return $this->notAsking(fn () => parent::getRealMethod());
    }

    public function isJson()
    {
        return $this->notAsking(fn () => parent::isJson());
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $run
     * @return T
     */
    private function notAsking(\Closure $run): mixed
    {
        return $this->visitor === null ? $run() : $this->visitor->notAsking($run);
    }
}
