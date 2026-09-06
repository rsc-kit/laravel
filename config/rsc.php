<?php

use RscKit\Support\System;

return [
    // JavaScript runtime that renders RSC. 'bun' (default) or 'node' — the
    // worker and build both run on either. Bun starts faster and needs no
    // separate install step on hosts where `rsc:install` vendors the binary.
    'runtime' => env('RSC_RUNTIME', 'bun'),

    // Explicit path to the runtime executable. Leave null to auto-discover
    // (Homebrew / /usr/local / ~/.bun / PATH). Relative paths resolve from the
    // app base path. Set this on hosts where the runtime is vendored into the
    // app — e.g. Laravel Cloud, where `php artisan rsc:install` downloads a
    // static binary the runtime image would not otherwise have.
    'binary' => env('RSC_RUNTIME_BINARY'),

    // Transport between PHP and the worker. 'unix' (default) uses a local Unix
    // domain socket — fastest, and lockable to the owner on shared hosts. Keep
    // this on any host where PHP and the worker share a filesystem, which
    // includes Laravel Cloud: `rsc:serve` runs as an App-cluster background
    // process in the same pod that serves web traffic.
    //
    // 'tcp' uses a loopback connection instead. Reach for it only when the two
    // genuinely cannot share a socket path — separate containers on a shared
    // network. With multiple workers, main ports are host:port..port+N-1 and
    // callback ports follow at port+N..port+2N-1.
    'transport' => env('RSC_TRANSPORT', 'unix'),
    'host' => env('RSC_HOST', '127.0.0.1'),
    'port' => (int) env('RSC_PORT', 7940),

    'socket_path' => env('RSC_SOCKET', '/tmp/laravel-rsc.sock'),
    'functions_dir' => env('RSC_FUNCTIONS_DIR', resource_path('rsc-functions')),

    // Number of worker processes. Each is a separate event loop, so this is the
    // primary throughput/concurrency knob. Defaults to one per CPU core (capped,
    // and bounded by the container's memory limit) when RSC_WORKERS is not set.
    'workers' => (int) env('RSC_WORKERS', 0) ?: System::defaultWorkerCount(),

    'enabled' => env('RSC_ENABLED', true),

    // The built RSC server bundle the worker loads (@vitejs/plugin-rsc output).
    'bundle' => env('RSC_BUNDLE', base_path('bootstrap/rsc/vite/dist/rsc/index.js')),

    // Name of the global your server components call to reach PHP. Both the
    // Vite plugin and the generated server actions read it from here, so the
    // two can never disagree about what the global is called.
    'host_global' => env('RSC_HOST_GLOBAL', 'rpc'),

    // Public dir + URL for the browser-facing client bundle. Served directly by
    // the web server (never through PHP); `assets_url` is the Vite base.
    'assets_dir' => env('RSC_ASSETS_DIR', public_path('build/rsc-vite')),
    'assets_url' => env('RSC_ASSETS_URL', '/build/rsc-vite/'),

    // How long a CDN may serve a prerendered PPR shell. The shell holds no
    // request-specific data — the dynamic parts arrive via the Flight request
    // the client bootstrap makes, which is never cached. Shells go stale on
    // redeploy, so purge the CDN on deploy or keep the TTL short.
    'shell_ttl' => (int) env('RSC_SHELL_TTL', 3600),
    'shell_stale_while_revalidate' => (int) env('RSC_SHELL_SWR', 86400),

    /*
     * Answering host calls over HTTP.
     *
     * The transport the engine is moving to: a renderer in front, this
     * application behind it answering rpc() over an ordinary POST. Off unless
     * a secret is set, because the endpoint runs registered functions by name
     * with none of this application's routing in front of it — a default of
     * "on and unauthenticated" is the kind that ships.
     *
     * Keep it unreachable from outside as well as authenticated: bind the
     * renderer to loopback, or put this endpoint on a listener only it can
     * reach.
     */
    'host_call_path' => env('RSC_HOST_CALL_PATH', '/__rsc/host-call'),
    'host_call_secret' => env('RSC_HOST_CALL_SECRET'),

    'callback_timeout' => 5,
    'stream_timeout' => (int) env('RSC_STREAM_TIMEOUT', 30),
    'static_path' => env('RSC_STATIC_PATH', storage_path('framework/rsc-static')),
    'body_size_limit' => env('RSC_BODY_SIZE_LIMIT', '1mb'),

    'entry_points' => array_filter(
        explode(',', env('RSC_ENTRY_POINTS', '')),
    ),
];
