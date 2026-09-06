<?php

namespace RscKit;

use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class PrerenderService
{
    /**
     * Discover ALL RSC routes (any route with _rsc_component default).
     *
     * @return Collection<int, Route>
     */
    public function discoverRscRoutes(): Collection
    {
        return collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => isset($route->defaults['_rsc_component']))
            ->filter(fn (Route $route) => in_array('GET', $route->methods()));
    }

    /**
     * Resolve the concrete URLs for a given route.
     *
     * Non-parameterized routes return a single URL.
     * Parameterized routes with staticPaths() return expanded URLs.
     * Parameterized routes without staticPaths() return an empty array.
     *
     * @return list<string>
     */
    public function resolveUrls(Route $route): array
    {
        $uri = $route->uri();

        if (! str_contains($uri, '{')) {
            return ['/'.ltrim($uri, '/')];
        }

        $staticPaths = $route->defaults['_static_paths'] ?? null;

        if ($staticPaths === null) {
            return [];
        }

        $paramNames = $route->parameterNames();

        return collect($staticPaths)->map(function ($params) use ($uri, $paramNames) {
            if (is_string($params)) {
                $params = [$paramNames[0] => $params];
            }

            $url = $uri;

            foreach ($params as $key => $value) {
                $url = str_replace(["{{$key}}", "{{$key}?}"], $value, $url);
            }

            return '/'.ltrim($url, '/');
        })->all();
    }

    /**
     * Pre-render a single URL and write static files.
     *
     * @return array{type: string, reason: string|null}
     */
    public function prerenderUrl(string $url, Route $route, string $outputPath): array
    {
        $rscResponse = $this->resolveRscResponse($route, $url);

        if (! $rscResponse instanceof RscResponse) {
            return ['type' => 'skipped', 'reason' => 'not an RscResponse'];
        }

        // Step 1: Classify using PPR shell render (fast, never hangs).
        // The stubbed host call returns a promise that never resolves, so a
        // page reaching for rpc() suspends and is classified by what it could
        // still paint. A page that reaches for nothing renders whole.
        $shell = app(RuntimeBridge::class)->rscPprShell(
            $rscResponse->getComponent(),
            $rscResponse->getProps(),
            $rscResponse->getLayouts(),
            $rscResponse->getLoadings(),
            $rscResponse->getParallelSlots(),
            // One url, so the page's params settle and it can render itself.
            $url,
        );

        if ($shell['timedOut']) {
            return ['type' => 'dynamic', 'reason' => 'awaits async data'];
        }

        // If page uses php() or other dynamic APIs, it's PPR — skip full render
        if ($shell['usedDynamicApis'] ?? false) {
            return ['type' => 'dynamic', 'reason' => 'usedDynamicApis'];
        }

        // Static page — full render to get HTML + Flight payload
        $shipsClientJs = $rscResponse->shipsClientJs();

        $result = app(RuntimeBridge::class)->rscWithoutCallbacks(
            $rscResponse->getComponent(),
            $rscResponse->getProps(),
            $rscResponse->getLayouts(),
            // Not empty: a page whose layout declares a parallel slot renders
            // without it otherwise, and nothing says so — the page comes out
            // whole apart from the missing region.
            $rscResponse->getLoadings(),
            $rscResponse->getParallelSlots(),
            0,
            '',
            $shipsClientJs,
        );

        // Without a runtime a client component is inert markup — a button that
        // does nothing — so this is refused rather than shipped.
        $clientComponents = $result['clientComponents'] ?? [];

        if (! $shipsClientJs && $clientComponents !== []) {
            return [
                'type' => 'error',
                'reason' => 'withoutClientJs(), but the tree renders '.implode(', ', $clientComponents)
                    .'. A client component needs the runtime this route is refusing to ship, so it '
                    .'would render as inert markup. These usually come from a shared layout rather '
                    .'than the page itself.',
            ];
        }

        // A render that failed is not a page to freeze — it is a page that
        // needs a request.
        //
        // React reports a failed row inside the payload rather than by
        // rejecting — a rejection inside a Suspense boundary never reaches the
        // caller — so the render "succeeds" and the result looks storable. It
        // is not: the browser decodes that row, throws "An error occurred in
        // the Server Components render", unmounts the document, and shows a
        // blank page with the error nowhere near the cause. Worse, the file
        // outlives the build, so every visitor gets it until someone rebuilds.
        //
        // Reported as dynamic rather than as a build error, because that is
        // what it means: this render happens with no host installed (see
        // rscWithoutCallbacks above), so a page whose data comes from PHP
        // cannot be frozen and must be served on demand. Failing the build
        // instead would stop anyone shipping a perfectly good page.
        //
        // The JS prerenderer already refuses these. This pass never learned to.
        if (self::payloadFailed($result['rscPayload'] ?? '')) {
            return ['type' => 'dynamic', 'reason' => 'the render reported an error'];
        }

        $version = $rscResponse->getVersion();

        // Apply page metadata (title, og:image, icons, etc.) from the RSC bundle
        // so buildMetaTags() can inject them into the pre-rendered HTML.
        if (isset($result['metadata']) && is_array($result['metadata'])) {
            $rscResponse->applyMetadataDefaults($result['metadata']);
        }

        $html = $this->buildHtmlPage($url, $rscResponse->getComponent(), $version, $result, $rscResponse);

        $path = trim($url, '/') ?: 'index';
        File::ensureDirectoryExists(dirname("{$outputPath}/{$path}.html"));

        File::put("{$outputPath}/{$path}.html", $html);
        File::put("{$outputPath}/{$path}.flight", $result['rscPayload']);

        // A payload for each depth a client might arrive holding, with the
        // layouts it already has left out. Without them every navigation to a
        // prerendered page is a whole document, which replaces the root and
        // throws away the pages retained behind it — so a form you were
        // filling in on the page you came from does not survive going back.
        //
        // One variant is not enough: a section with its own layout has a
        // longer chain than the page you came from, so the shared depth is
        // less than the whole chain and only a variant for THAT depth fits.
        $chain = array_column($rscResponse->getLayouts(), 'component');

        for ($depth = 1; $depth <= count($chain); $depth++) {
            $segment = app(RuntimeBridge::class)->rscPayload(
                $rscResponse->getComponent(),
                $rscResponse->getProps(),
                $rscResponse->getLayouts(),
                $depth,
                '/'.ltrim($url, '/'),
                $rscResponse->getLoadings(),
                $rscResponse->getParallelSlots(),
            );

            File::put("{$outputPath}/{$path}.seg{$depth}.flight", $segment['rscPayload']);
        }

        $meta = [
            'clientChunks' => $result['clientChunks'],
            'version' => $version,
            'layouts' => $chain,
        ];

        $viewData = $rscResponse->getViewData();

        if (isset($viewData['title'])) {
            $meta['title'] = $viewData['title'];
        }

        File::put("{$outputPath}/{$path}.meta.json", json_encode($meta, JSON_THROW_ON_ERROR));

        return ['type' => 'static', 'reason' => null];
    }

    /**
     * Remove every artifact previously written for a url.
     *
     * A route's classification is not fixed: making a static page read the
     * request turns it into PPR, and the two write different files —
     * `.html`/`.flight` versus `.ppr.html`. Nothing removed the old set, and
     * ServeStaticRsc prefers `.flight`, so the page went on serving a build
     * from before the change. Every query returned the same frozen copy, with
     * nothing to say it had happened. `--clean` fixed it, once you knew.
     *
     * Called before writing, so each build leaves exactly the artifacts that
     * belong to what the route is now.
     */
    public function purgeArtifacts(string $outputPath, string $url): void
    {
        $path = trim($url, '/') ?: 'index';
        $base = "{$outputPath}/{$path}";

        foreach (['.html', '.flight', '.meta.json', '.ppr.html', '.ppr-meta.json', '.postponed.json'] as $suffix) {
            File::delete($base.$suffix);
        }

        // One per layout depth, and how many there are is a property of the
        // build being replaced rather than of this one.
        foreach (File::glob($base.'.seg*.flight') ?: [] as $segment) {
            File::delete($segment);
        }
    }

    public function resolveRscResponse(Route $route, string $url): mixed
    {
        $params = $this->extractParams($route, $url);

        $request = app('request');
        $request->setRouteResolver(fn () => $route);
        $route->bind($request);

        foreach ($params as $key => $value) {
            $route->setParameter($key, $value);
        }

        $action = $route->getAction('uses');

        return app()->call($action, $params);
    }

    public const PPR_PAYLOAD_MARKER = '<!--__RSC_PPR_PAYLOAD__-->';

    /** Shown only when a shell render flushed nothing before being aborted. */
    private const FALLBACK_SKELETON = '<div style="padding:40px"><div style="height:32px;width:280px;background:rgba(255,255,255,0.06);border-radius:8px;margin-bottom:16px;animation:pulse 1.5s ease-in-out infinite"></div><div style="height:200px;background:rgba(255,255,255,0.03);border-radius:12px;border:1px solid rgba(255,255,255,0.06);animation:pulse 1.5s ease-in-out infinite"></div><style>@keyframes pulse{0%,100%{opacity:.4}50%{opacity:.8}}</style></div>';

    public const NONCE_PLACEHOLDER = '__RSC_CSP_NONCE__';

    /**
     * Pre-render a PPR shell for a parameterized route.
     *
     * Uses handleRscPprShell which mocks php() so async components suspend
     * and Suspense shows fallback content. The shell (layout + fallbacks)
     * is captured and cached for all param values.
     *
     * @return array{type: string, reason: string|null}
     */
    public function prerenderPprShell(string $uri, Route $route, string $outputPath): array
    {
        // Use placeholder params to resolve the route controller
        $paramNames = $route->parameterNames();
        $dummyParams = [];

        foreach ($paramNames as $name) {
            $dummyParams[$name] = '__ppr__';
        }

        $dummyUrl = ltrim($uri, '/');

        foreach ($dummyParams as $key => $value) {
            $dummyUrl = str_replace(["{{$key}}", "{{$key}?}"], $value, $dummyUrl);
        }

        $dummyUrl = '/'.ltrim($dummyUrl, '/');

        $rscResponse = $this->resolveRscResponse($route, $dummyUrl);

        if (! $rscResponse instanceof RscResponse) {
            return ['type' => 'skipped', 'reason' => 'not an RscResponse'];
        }

        $result = app(RuntimeBridge::class)->rscPprShell(
            $rscResponse->getComponent(),
            $rscResponse->getProps(),
            $rscResponse->getLayouts(),
            $rscResponse->getLoadings(),
            $rscResponse->getParallelSlots(),
        );

        // A timeout is the normal path for a PPR page: the shell render is
        // aborted deliberately once React has flushed everything that does not
        // depend on request data, so the captured markup IS the shell — layouts,
        // static content and Suspense fallbacks. Only fall back to a neutral
        // skeleton when the render produced nothing at all.
        $shellBody = trim((string) ($result['shellHtml'] ?? '')) !== ''
            ? $result['shellHtml']
            : self::FALLBACK_SKELETON;

        $version = $rscResponse->getVersion();

        if (RscKitServiceProvider::cspNonce() !== null) {
            app()->instance('csp-nonce', self::NONCE_PLACEHOLDER);
        }

        // Aborting mid-render leaves the document unclosed.
        $html = $this->closeDocument($shellBody);
        $html = $this->injectMissingMetaTags($html, $rscResponse->buildMetaTags());

        // Store under the URI pattern (e.g. posts/_id_)
        $path = trim(str_replace(['{', '}'], ['_', '_'], ltrim($uri, '/')), '/') ?: 'index';
        File::ensureDirectoryExists(dirname("{$outputPath}/{$path}.ppr.html"));

        File::put("{$outputPath}/{$path}.ppr.html", $html);

        $meta = [
            'clientChunks' => $result['clientChunks'],
            'version' => $version,
            'component' => $rscResponse->getComponent(),
            'layouts' => $rscResponse->getLayouts(),
            'parameterized' => true,
            'uriPattern' => $uri,
            // Everything the render was given, so finishing it later can replay
            // the same call rather than reconstruct one. A resume matches React's
            // slots by key, so an argument that differs from the frozen render at
            // all is a tree that "doesn't match" — and the failure is silent:
            // every boundary falls back to client rendering and the page still
            // looks right.
            'renderedWith' => [
                'props' => $rscResponse->getProps(),
                'loadings' => $rscResponse->getLoadings(),
                'parallelSlots' => $rscResponse->getParallelSlots(),
                // A pattern shell stands for every url its route matches, so it
                // was rendered for none of them.
                'pageKey' => '',
            ],
        ];

        File::put("{$outputPath}/{$path}.ppr-meta.json", json_encode($meta, JSON_THROW_ON_ERROR));

        $this->writePostponed($outputPath, $path, $result);

        return ['type' => 'ppr', 'reason' => null];
    }

    /**
     * Pre-render a PPR page — shell with Suspense fallbacks and a placeholder
     * for the Flight payload.
     *
     * Uses handleRscPprShell which mocks php() so async components suspend.
     * At request time, the shell is served instantly and a fresh RSC render
     * streams Suspense completions + Flight payload.
     *
     * @return array{type: string, reason: string|null}
     */
    public function prerenderPpr(string $url, Route $route, string $outputPath): array
    {
        $rscResponse = $this->resolveRscResponse($route, $url);

        if (! $rscResponse instanceof RscResponse) {
            return ['type' => 'skipped', 'reason' => 'not an RscResponse'];
        }

        $result = app(RuntimeBridge::class)->rscPprShell(
            $rscResponse->getComponent(),
            $rscResponse->getProps(),
            $rscResponse->getLayouts(),
            $rscResponse->getLoadings(),
            $rscResponse->getParallelSlots(),
            // One url. The page's params settle; only what genuinely needs the
            // request — a php() call — stays suspended for the client to fill.
            $url,
        );

        // A timeout is the normal path for a PPR page: the shell render is
        // aborted deliberately once React has flushed everything that does not
        // depend on request data, so the captured markup IS the shell — layouts,
        // static content and Suspense fallbacks. Only fall back to a neutral
        // skeleton when the render produced nothing at all.
        $shellBody = trim((string) ($result['shellHtml'] ?? '')) !== ''
            ? $result['shellHtml']
            : self::FALLBACK_SKELETON;

        $version = $rscResponse->getVersion();

        // Aborting mid-render leaves the document unclosed.
        $html = $this->closeDocument($shellBody);
        $html = $this->injectMissingMetaTags($html, $rscResponse->buildMetaTags());

        $path = trim($url, '/') ?: 'index';
        File::ensureDirectoryExists(dirname("{$outputPath}/{$path}.html"));

        File::put("{$outputPath}/{$path}.ppr.html", $html);

        $meta = [
            'clientChunks' => $result['clientChunks'],
            'version' => $version,
            'component' => $rscResponse->getComponent(),
            'layouts' => $rscResponse->getLayouts(),
            // See the note on the parameterised path: replayed, not rebuilt.
            'renderedWith' => [
                'props' => $rscResponse->getProps(),
                'loadings' => $rscResponse->getLoadings(),
                'parallelSlots' => $rscResponse->getParallelSlots(),
                'pageKey' => $url,
            ],
        ];

        $this->writePostponed($outputPath, $path, $result);

        $viewData = $rscResponse->getViewData();

        if (isset($viewData['title'])) {
            $meta['title'] = $viewData['title'];
        }

        File::put("{$outputPath}/{$path}.ppr-meta.json", json_encode($meta, JSON_THROW_ON_ERROR));

        return ['type' => 'ppr', 'reason' => null];
    }

    /**
     * Close a document left unterminated by an aborted shell render.
     */
    /**
     * Store where the build-time render stopped, beside the shell it produced.
     *
     * Only written when there is something to resume from. Its absence is
     * meaningful rather than incidental: a host that finds no state serves the
     * shell and lets the client fill it, which is what every build before this
     * one did.
     */
    protected function writePostponed(string $outputPath, string $path, array $result): void
    {
        $postponed = $result['postponed'] ?? null;

        if ($postponed === null) {
            return;
        }

        File::put("{$outputPath}/{$path}.postponed.json", json_encode($postponed, JSON_THROW_ON_ERROR));
    }

    protected function closeDocument(string $html): string
    {
        if (stripos($html, '</body>') === false && stripos($html, '<body') !== false) {
            $html .= '</body>';
        }

        if (stripos($html, '</html>') === false && stripos($html, '<html') !== false) {
            $html .= '</html>';
        }

        return $html;
    }

    /**
     * Inject only the metadata tags the rendered document does not already have.
     *
     * React 19 hoists <title>/<meta> rendered inside the tree into <head>, so a
     * page's `metadata` export is already in the markup. Appending buildMetaTags()
     * wholesale would leave every prerendered page with two <title> tags; this
     * adds back just the keys that came from route.php viewData, which React
     * never saw.
     */
    protected function injectMissingMetaTags(string $html, string $metaTags): string
    {
        if (trim($metaTags) === '' || stripos($html, '</head>') === false) {
            return $html;
        }

        $head = substr($html, 0, stripos($html, '</head>'));
        $keep = [];

        foreach (preg_split('/\R/', $metaTags) ?: [] as $tag) {
            if (trim($tag) === '' || $this->headAlreadyHas($head, $tag)) {
                continue;
            }

            $keep[] = $tag;
        }

        if ($keep === []) {
            return $html;
        }

        return str_ireplace('</head>', implode("\n", $keep)."\n</head>", $html);
    }

    /**
     * Is this tag's identity — <title>, or a meta name/property, or a link rel —
     * already present in the rendered <head>?
     */
    protected function headAlreadyHas(string $head, string $tag): bool
    {
        if (stripos($tag, '<title') !== false) {
            return stripos($head, '<title') !== false;
        }

        foreach (['name', 'property', 'rel'] as $attribute) {
            if (preg_match('/'.$attribute.'="([^"]+)"/i', $tag, $m)) {
                return stripos($head, $attribute.'="'.$m[1].'"') !== false;
            }
        }

        return false;
    }

    /**
     * Whether a Flight payload carries an error row.
     *
     * React writes one as `<id>:E{...}` — a row the client throws when it
     * reaches it. Matched on the row prefix rather than anywhere in the text,
     * because a page that merely contains the characters E{ in its content is
     * not a failed render.
     */
    private static function payloadFailed(string $payload): bool
    {
        return preg_match('/(^|\n)[0-9a-f]+:E\{/i', $payload) === 1;
    }

    /**
     * @param  array{body: string, rscPayload: string, clientChunks: string[]}  $result
     */
    public function buildHtmlPage(string $url, string $component, string $version, array $result, RscResponse $rscResponse): string
    {
        // Use a placeholder nonce during prerender so the cached HTML can be
        // patched with the real per-request nonce at serve time.
        if (RscKitServiceProvider::cspNonce() !== null) {
            app()->instance('csp-nonce', self::NONCE_PLACEHOLDER);
        }

        // The worker returns a COMPLETE HTML document (the root layout renders
        // <html> and @vitejs/plugin-rsc injects the client bootstrap + CSS).
        // Inject page metadata tags (title, og:*, icons) into <head>.
        return $this->injectMissingMetaTags($result['body'], $rscResponse->buildMetaTags());
    }

    /**
     * Start or connect to the Bun worker process.
     *
     * @return Process|null|false Process if started, null if already running, false on failure
     */
    public function ensureBunWorker(bool $forceRestart = false): Process|null|false
    {
        // Readiness is a ping, never a file-existence check. The socket path
        // scheme lives in RuntimeBridge alone; rebuilding it here is what left
        // this waiting on a file the worker no longer creates.
        if ($forceRestart) {
            $this->clearWorkerSockets();
        } elseif ($this->workerResponds()) {
            return null;
        }

        $process = new Process([PHP_BINARY, base_path('artisan'), 'rsc:serve']);
        $process->setTimeout(null);
        $process->start(function ($type, $buffer): void {
            // Forward worker output to stderr so the user can see startup errors
            fwrite(STDERR, $buffer);
        });

        $maxWait = 15;
        $waited = 0;

        while ($waited < $maxWait) {
            usleep(500_000);
            $waited += 0.5;

            if ($this->workerResponds()) {
                return $process;
            }

            if (! $process->isRunning()) {
                return false;
            }
        }

        $process->stop(5);

        return false;
    }

    /**
     * True when at least one worker answers a ping.
     *
     * The singleton is dropped first so the bridge reconnects rather than
     * reusing a pooled socket to a worker that has since been replaced.
     */
    private function workerResponds(): bool
    {
        try {
            app()->forgetInstance(RuntimeBridge::class);

            return app(RuntimeBridge::class)->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Drop a previous worker's connections and socket files so a restart binds
     * cleanly instead of inheriting a stale listener.
     */
    private function clearWorkerSockets(): void
    {
        try {
            $bridge = app(RuntimeBridge::class);
            $bridge->disconnect();

            foreach ($bridge->socketFiles() as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        } catch (\Throwable) {
            // Nothing connected yet; there is nothing to clear.
        }

        app()->forgetInstance(RuntimeBridge::class);
    }

    /**
     * @return array<string, string>
     */
    private function extractParams(Route $route, string $url): array
    {
        $paramNames = $route->parameterNames();

        if (empty($paramNames)) {
            return [];
        }

        $uri = $route->uri();
        $pattern = preg_replace('/\{(\w+)\??\}/', '(?P<$1>[^/]+)', $uri);
        $urlPath = ltrim($url, '/');
        $params = [];

        if (preg_match('#^'.$pattern.'$#', $urlPath, $matches)) {
            foreach ($paramNames as $name) {
                if (isset($matches[$name])) {
                    $params[$name] = $matches[$name];
                }
            }
        }

        return $params;
    }
}
