<?php

return [
    /*
     * Where the renderer listens.
     *
     * Anything Laravel does not route is handed to it, so an app parked in
     * ~/Herd works at its own .test domain with nothing else configured. The
     * fallback only runs when no real route matched, so the endpoint below and
     * the app's own routes always win.
     *
     * Null by default, and only in development does that mean "off" — there
     * the hot file below supplies the url while a dev server is running.
     *
     * Setting it in production opts into serving pages THROUGH Laravel, and
     * costs a worker for the length of every render while the renderer calls
     * back into this same application for data. With W workers that caps
     * concurrent renders at W-1, and if every worker is blocked proxying, the
     * calls have nobody to answer them. Give the host calls their own PHP-FPM
     * pool if you do it.
     *
     * The alternative needs no proxy and no setting: point the domain at the
     * renderer and let it call back here. A worker is then held for the length
     * of a host CALL rather than a whole render.
     */
    'renderer_url' => env('RSC_RENDERER_URL'),

    /*
     * Written by the dev server while it runs, and removed when it stops.
     *
     * Read before renderer_url, because a dev server chooses its port at
     * runtime: 5173 is the most contended port on a developer's machine, and
     * Vite moves to the next free one without saying so. Following the file
     * means another project running does not silently break this one.
     */
    'hot_file' => env('RSC_HOT_FILE', public_path('rsc-hot')),
    'renderer_timeout' => (float) env('RSC_RENDERER_TIMEOUT', 60),

    /*
     * Answering host calls from the renderer.
     *
     * The renderer owns the request and calls back here for data, for the
     * session, and to ask whether a route may render. The endpoint is not
     * registered at all unless a secret is set — it runs registered functions
     * by name with none of the application's routing in front of it, and a
     * default of "on and unauthenticated" is the kind that ships.
     *
     * Keep it unreachable from outside as well as authenticated: bind the
     * renderer to loopback, or put this endpoint on a listener only it can
     * reach. It can serve a unix socket, which HTTP runs over unchanged and
     * which opens no port at all.
     */
    /*
     * The route table the build writes, read so the renderer's pages win.
     *
     * With Laravel in front, everything it does not route falls through to the
     * renderer - but a url Laravel does route never gets there, and a fresh
     * application routes `/` to its welcome page. The renderer's pages are
     * registered from this file after routes/web.php has loaded - the last
     * route for a url wins - so the rule is the one the docs state: if the
     * React tree has it, React renders it.
     * Written by `vite` and `vite build` into the plugin's outDir.
     */
    'routes_manifest' => env('RSC_ROUTES_MANIFEST', base_path('bootstrap/rsc/vite/routes.json')),

    /*
     * Where the server actions live - the classes the build writes a
     * "use server" stub for, which `rsc:host-manifest` writes to rsc-host.json. The functions a
     * server component reaches through rpc() are found under app/Rsc as a
     * whole, this directory included.
     */
    'actions_dir' => env('RSC_ACTIONS_DIR', app_path('Rsc/Actions')),

    'host_call_path' => env('RSC_HOST_CALL_PATH', '/__rsc/host-call'),
    'host_call_secret' => env('RSC_HOST_CALL_SECRET'),

    /*
     * Where Rsc::changed() keeps versions for refreshOn.
     *
     * 'cache' (the default, and the recommendation): the app's cache. The
     * renderer asks PHP which versions moved about every two seconds while any
     * tab is watching - one small request per renderer process, however many
     * tabs, since PHP cannot hold the question open.
     *
     * 'database': the rsc_versions table (php artisan vendor:publish
     * --tag=rsc-migrations), for pruning on a schedule or for writers outside
     * Laravel - a Go service, another language's worker. Laravel still
     * answers the renderer from it. On Postgres each change also sends a
     * NOTIFY, for a renderer that reads the table itself (see PROTOCOL.md).
     */
    'versions' => env('RSC_VERSIONS', 'cache'),
    'versions_table' => env('RSC_VERSIONS_TABLE', 'rsc_versions'),
    'versions_connection' => env('RSC_VERSIONS_CONNECTION'),

    /*
     * How long a name nobody changes is kept: cache keys expire after it, and
     * `php artisan rsc:prune-versions` deletes table rows older than it.
     * Deleting is always safe; a tab still holding the name refreshes once.
     */
    'versions_keep_days' => (int) env('RSC_VERSIONS_KEEP_DAYS', 30),

    /*
     * For 'cache': a store every server shares; null is the default store.
     * A store that is per server (file, array) means a change on one server
     * is not seen from another.
     */
    'versions_store' => env('RSC_VERSIONS_STORE'),
];
