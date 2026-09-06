<?php

namespace RscKit\Http;

use Illuminate\Http\Request;
use RscKit\RendererNotRunningException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands a request Laravel does not answer to the renderer.
 *
 * The renderer owns the route table — the file tree is the routing, and there
 * are no page routes here to register. But a Laravel application is expected to
 * BE its .test domain: parked in ~/Herd, opened at its own name, with the
 * framework's middleware, sessions and error pages in front. Making someone put
 * a second origin in the address bar to see their own app is not a trade worth
 * making.
 *
 * So Laravel stays the front door and forwards. Registered as a fallback, so
 * every real route still wins — the host-call endpoint, the app's own routes,
 * anything a package added — and only what nothing matched arrives here.
 *
 * One thing to know before running this under load: a PHP worker is occupied
 * for the length of a render, and the renderer calls back into this same
 * application for its data. With too few workers those calls have nobody to
 * answer them and the two sides wait for each other. Development is fine —
 * Herd runs several — and a deployment that cares should put the renderer in
 * front and let it call back here, which needs no proxy at all.
 */
class RendererProxy
{
    /** Headers describing THIS hop, which must not be forwarded either way. */
    private const HOP_BY_HOP = [
        'connection', 'keep-alive', 'transfer-encoding', 'upgrade',
        'proxy-authenticate', 'proxy-authorization', 'te', 'trailer',
        'content-length', 'host',
    ];

    public function __invoke(Request $request): StreamedResponse
    {
        $renderer = $this->rendererUrl();

        // Nothing to hand this to, and what that means depends on who is
        // looking.
        //
        // With debug on, a developer is: they have opened their own app and it
        // did not render. A 404 would tell them the route does not exist, which
        // is untrue and the hardest possible thing to act on — the route is
        // fine, the renderer is not running. Say that, and say what to run.
        //
        // With debug off this is an ordinary 404, and has to be. The normal
        // production shape puts the renderer in front, so page requests never
        // reach Laravel at all and whatever does arrive here genuinely has no
        // route — a bot, a stale link, a typo. Answering those with setup
        // instructions would be useless to them and would tell a stranger how
        // the application is wired.
        if ($renderer === null) {
            if (config('app.debug')) {
                throw new RendererNotRunningException;
            }

            abort(404);
        }

        $target = rtrim($renderer, '/').$request->getRequestUri();

        $status = 200;
        $headers = [];
        $headersDone = false;
        $pending = '';

        $handle = curl_init($target);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_HTTPHEADER => $this->forwardedHeaders($request),
            CURLOPT_RETURNTRANSFER => false,
            // A redirect is the renderer's answer and belongs to the browser.
            // Following it would return the destination's body under the
            // original url.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) config('rsc.renderer_timeout', 60),
            CURLOPT_HEADERFUNCTION => function ($_, string $line) use (&$status, &$headers, &$headersDone): int {
                $length = strlen($line);
                $trimmed = trim($line);

                if ($trimmed === '') {
                    $headersDone = true;

                    return $length;
                }

                if (str_starts_with($trimmed, 'HTTP/')) {
                    // Reset rather than append: a 1xx leaves an earlier block in
                    // the same stream, and the last one is the real response.
                    $status = (int) (explode(' ', $trimmed)[1] ?? 200);
                    $headers = [];

                    return $length;
                }

                [$name, $value] = array_pad(explode(':', $trimmed, 2), 2, '');
                $name = strtolower(trim($name));

                if ($name !== '' && ! in_array($name, self::HOP_BY_HOP, true)) {
                    $headers[$name][] = trim($value);
                }

                return $length;
            },
            CURLOPT_WRITEFUNCTION => function ($_, string $chunk) use (&$pending): int {
                $pending .= $chunk;

                return strlen($chunk);
            },
        ]);

        if ($request->getMethod() !== 'GET' && $request->getMethod() !== 'HEAD') {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->getContent());
        }

        // curl rather than fopen, and this is why: PHP's http stream wrapper
        // buffers. Measured against a page with three Suspense boundaries, its
        // reads arrived at 0.04s and then nothing until 4.02s — the shell went
        // out and every reveal in between was held to the end. curl hands each
        // chunk over as it lands.
        //
        // curl_multi rather than curl_exec, because a StreamedResponse is built
        // with its status and headers before its body callback runs, and
        // curl_exec would not return them until the whole transfer finished.
        // Pumped just far enough to read the response head, then handed on.
        $multi = curl_multi_init();
        curl_multi_add_handle($multi, $handle);

        $running = 0;

        do {
            curl_multi_exec($multi, $running);

            if ($headersDone || $running === 0) {
                break;
            }

            curl_multi_select($multi, 0.05);
        } while (true);

        if (! $headersDone && $running === 0 && $pending === '') {
            curl_multi_remove_handle($multi, $handle);
            curl_multi_close($multi);

            throw new RendererNotRunningException($renderer);
        }

        // nginx buffers a FastCGI response by default, which holds every chunk
        // until the render finishes — the shell included. This is how nginx is
        // told not to, and Herd, Forge and most ingress setups honour it.
        // Without it the page paints in one go at the end, which looks exactly
        // like a framework that never streamed.
        $headers['x-accel-buffering'] = ['no'];

        return new StreamedResponse(function () use ($multi, $handle, &$pending, &$running) {
            // Every buffer between here and the socket, closed. PHP's own
            // output buffering is the first: Laravel and the SAPI may each have
            // started one, and echo into a buffer goes nowhere until it fills.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            ob_implicit_flush(true);

            $emit = function () use (&$pending) {
                if ($pending === '') {
                    return;
                }

                echo $pending;
                $pending = '';
                flush();
            };

            $emit();

            while ($running > 0) {
                curl_multi_select($multi, 0.05);
                curl_multi_exec($multi, $running);
                $emit();
            }

            $emit();

            curl_multi_remove_handle($multi, $handle);
            curl_multi_close($multi);
        }, $status, $headers);
    }

    /**
     * The request's headers, minus the ones describing this hop.
     *
     * @return list<string>
     */
    private function forwardedHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            if (in_array(strtolower($name), self::HOP_BY_HOP, true)) {
                continue;
            }

            foreach ($values as $value) {
                $headers[] = $name.': '.$value;
            }
        }

        // The visitor's own address and the name they typed, not this hop's.
        $headers[] = 'X-Forwarded-For: '.$request->ip();
        $headers[] = 'X-Forwarded-Host: '.$request->getHost();
        $headers[] = 'X-Forwarded-Proto: '.$request->getScheme();

        return $headers;
    }

    /**
     * Where the renderer is.
     *
     * The hot file first, for the same reason Laravel's own Vite integration
     * reads one: a dev server picks its port at runtime, and 5173 is the most
     * contended port on a developer's machine — Vite quietly moves to the next
     * free one when another project already holds it. A configured url is the
     * answer for a built deployment, where the port is decided in advance.
     */
    private function rendererUrl(): ?string
    {
        // The hot file first, and in development it is the only source: a dev
        // server picks its port at runtime, and when another project already
        // holds 5173 on IPv4 Vite binds IPv6 and keeps the number — so a url
        // built from the port looks reachable and answers nothing.
        //
        // The file exists only while a dev server is running, which is exactly
        // when pages should be proxied. Nothing to configure, and nothing left
        // switched on in production by accident.
        $hot = config('rsc.hot_file');

        if ($hot && is_file($hot)) {
            $url = trim((string) file_get_contents($hot));

            if ($url !== '') {
                return $url;
            }
        }

        return config('rsc.renderer_url') ?: null;
    }
}
