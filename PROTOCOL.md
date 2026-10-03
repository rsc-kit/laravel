# The host-call protocol

What the renderer sends Laravel and what Laravel answers, as this package
implements it. The contract every backend answers is
[docs.rsc-kit.dev/hosts/your-own-backend](https://docs.rsc-kit.dev/hosts/your-own-backend);
this is the same contract, with where each part lives in `src/`.

The renderer (`@rsc-kit/core`, under Vite in development and Nitro in
production) owns routing, rendering, prerendering and static serving. Laravel
answers one endpoint, over plain HTTP. There is no socket, no framing and no
PHP-side rendering.

## The endpoint

`POST /__rsc/host-call` (`rsc.host_call_path`, `RSC_HOST_CALL_PATH`),
registered only when `RSC_HOST_CALL_SECRET` is set — absent, not open.

```http
POST /__rsc/host-call
Content-Type: application/json
X-Rsc-Host-Secret: <RSC_HOST_CALL_SECRET>
Cookie: <the visitor's, forwarded unchanged>
Authorization: <likewise, if the page request had one>

{ "function": "Orders.recent", "args": [5] }
```

- **The secret is checked first**, in constant time, before anything is
  dispatched; a missing or wrong one is **403**. An empty configured secret
  authorises nobody: `hash_equals('', '')` is true, so it is rejected before
  the comparison rather than trusted to it (`HostCallDispatcher::authorises`).
- **`Cookie` and `Authorization`** are the only headers the renderer
  forwards. The route carries `EncryptCookies`, `AddQueuedCookiesToResponse`
  and `StartSession`, so `auth()->user()` inside a call is the visitor the
  page is being rendered for. During a build there is no visitor and the
  headers are absent.
- **No CSRF.** `VerifyCsrfToken` is deliberately not on the route: CSRF
  protects a browser tricked into posting with its cookies, and the caller
  here holds a secret a browser cannot be tricked into sending.
- **`function`** is the name `rpc()` was given: `Class.method` for a class
  under `app/Rsc`, the class name for an invokable one. **`args`** is
  positional, exactly as passed, and must be a list.

Malformed requests: a body that is not a JSON object, a missing `function` or
non-list `args` is **400**; a name nothing is registered for is **404**,
listing the registered names only under `app.debug`.

## The reply

JSON. The fields keep the outcomes apart, so the renderer never reads a
message to tell an invalid form from a broken server
(`HostCallDispatcher::dispatch`):

| outcome | thrown in PHP | status | fields |
| --- | --- | --- | --- |
| the answer | — | 200 | `result` |
| …and the call marked regions stale | `Rsc::revalidate('orders')` | 200 | `result`, `revalidate: ["orders"]` |
| the input is invalid | `ValidationException` | 422 | `validationErrors: { "email": ["…"] }`, `error` |
| no session | `AuthenticationException` | 401 | `unauthenticated: true`, `error` |
| a session, and still no | `AuthorizationException` | 403 | `unauthorized: true`, `error` |
| go somewhere else | `RscRedirectException` | **200** | `redirect: "/login"`, `redirectStatus` |
| a middleware aborted | `HttpException` (`abort(429)`) | that status | `error`, `refusalStatus: 429` |
| the function failed | anything else | 500 | `error`, and under `app.debug` `debug` |

- **A redirect is a 200.** An HTTP client follows a 3xx transparently, so a
  real one would send the host call itself to the destination and hand back
  whatever it found as the function's result. `redirectStatus` is the
  redirect's own status (302 unless the middleware said otherwise); the
  engine's default when it is absent is 307.
- **Refusing is not failing.** `validationErrors` is read before `error`, so
  a reply carrying both is a refusal with fields. `error` alone, with a 500,
  is reserved for what the visitor did not cause. A 5xx from `abort()` is
  reported to the log like any other failure.
- **A failure is reported**, because the endpoint answers it itself and
  Laravel's handler never sees it. Without `app.debug` its `error` is
  `"Server Error"`: the real message may name a query or a path. With it,
  `error` is the message and `debug` says where it happened:

  ```json
  { "error": "boom", "debug": { "type": "RuntimeException", "message": "boom",
    "trace": ["app/Rsc/Orders.php:42", "app/…:17 App\\Rsc\\Orders->recent()", "…"] } }
  ```

  Frames are newest first, paths relative to the app. The renderer puts it on
  the render's error as its `cause`. A refusal never carries one.
- **A result is encoded as `JsonResponse` would**: a `Jsonable`,
  `JsonSerializable` or `Arrayable` at the top level goes through its own
  method. A result JSON cannot carry (`INF`, invalid UTF-8) is that call's
  500, not an exception after the call has run.
- **`revalidate`** is taken on every way out of a call, so a call that marked
  a region and then threw does not leave its mark for the next one.
- A cookie queued during a single call (`Auth::login()`, say) rides on this
  response, and the renderer puts it on the page's.

## Batches

Calls the renderer issued in one tick of a render arrive as one POST:

```json
{ "calls": [ { "function": "Orders.recent", "args": [5] }, { "function": "Me.profile", "args": [] } ] }
```

Answered as **NDJSON** (`Content-Type: application/x-ndjson`,
`X-Accel-Buffering: no`), one line per call, flushed the moment it finishes,
each with its `index` in the batch, the `status` it would have had alone, and
its reply fields:

```text
{"index":0,"status":200,"result":[…]}
{"index":1,"status":401,"unauthenticated":true,"error":"Unauthenticated."}
```

- Laravel runs the calls **in order**, one after another; a fast first read
  still paints before a slow third has begun.
- **Every call is answered.** A refusal in the second is that call's line,
  not a reason to leave the third out. Each call keeps its own `revalidate`.
- At most **50** calls (`HostCallDispatcher::BATCH_LIMIT`, the engine's own
  limit); more is **413**. An empty or non-list `calls` is **400**. Both are
  a single JSON reply, before any line is written.
- Headers leave before the first call runs, so a cookie a batched call queues
  has nothing to ride on. Reads do not set cookies, and an action is never
  batched. The session is saved once, after the last call.

The engine also reads a whole-batch answer, `{ "replies": [ { "status": 200,
"result": … }, … ] }`, one per call in order. This package does not send it
over HTTP.

## Route middleware

A `middleware.ts` beside or above a page names middleware in Laravel's
vocabulary:

```ts
export const middleware = ['auth', 'verified', 'can:update,post']
```

Before anything at or below it renders — including a page frozen at build
time, before the file is served — the renderer calls the reserved function
with the list:

```json
{ "function": "__rsc.middleware", "args": [["auth", "verified", "can:update,post"]] }
```

`RouteMiddleware::run()` resolves the names through the router (aliases and
groups), runs them through Laravel's pipeline against the forwarded request,
and answers `{ "result": true }` only when the pipeline reached its end.
Anything else is a refusal, and the engine reads anything but a literal
`true` as one:

- a middleware that throws answers as the table above — an
  `AuthenticationException` becomes a `redirect` to the login route when the
  app has one, `unauthenticated` when not;
- a middleware that returns a redirect (`verified`, `guest`) becomes
  `redirect` with its status;
- one that returns any other response fails with that response's status, or
  403 when the status would read as success.

`__rsc.middleware` is registered by the service provider, not discovered, and
is left out of the manifest.

## Saying data changed

A page or a section names what it refreshes on:

```ts
export default section('repos', Repos, { refreshOn: ({ params }) => [`team:${params.team}:repos`] })
```

and this side says when that changed — from a webhook, a job, a listener,
anywhere, not only an action:

```php
Rsc::changed("team:$teamId:repos");
```

Every open tab showing it refreshes. A name has a version, a number in the
app's cache that moves when it is said to have changed; nothing else travels.
The renderer reads versions at render and, for every open tab, asks the
reserved function for the ones that moved since:

```json
{ "function": "__rsc.changed", "args": [{ "since": { "team:1:repos": 3 }, "wait": 5000 }] }
```

The answer is `{ "result": { "versions": { "team:1:repos": 4 } } }` — only
the names whose version differs from `since`, an empty object when none does;
a name never changed is at 0. `wait` is how long the renderer would let the
call be held for one to move. Laravel answers at once — a PHP-FPM worker held
open is one the next request waits for — and the renderer asks again on its
interval, a couple of seconds apart. The protocol allows either, which is
what lets the same page run on a backend that can wait and one that cannot.

`__rsc.changed` is registered by the service provider, not discovered, and is
left out of the manifest. The versions live in the default cache store, or
the one `rsc.versions_store` names: a store every server shares, since a webhook
lands on one server and a tab's stream is held by whichever renderer it
reached.

## rsc-host.json

`php artisan rsc:host-manifest` writes it at the project root, beside
`vite.config.ts`; `--print` writes it to stdout instead. The build reads it
(`rscKit({ hostManifest: { command: ['php', 'artisan', 'rsc:host-manifest'] } })`
runs it as dev and every build start), and `make:rsc-action` writes it too.

```json
{
  "actions": { "ordersCancel": "Orders.cancel" },
  "functions": ["Orders.cancel", "Posts.latest"],
  "types": {
    "Orders.cancel": { "params": [{ "type": "integer" }] },
    "Posts.latest": { "params": [{ "type": "integer" }], "optional": 1, "result": [] }
  },
  "defs": {}
}
```

- **`actions`** maps the JavaScript name the build exports from
  `server-actions.generated.ts` to the name a call arrives under. Discovered by
  reflection over `app/Rsc/Actions` (`rsc.actions_dir`), inherited methods
  included: `App\Rsc\Actions\Orders::cancel` is `ordersCancel`, called as
  `Orders.cancel` (the class's short name). Always an object, even when empty.
- **`functions`** is every name `rpc()` may call, sorted; the build turns it
  into the type of `rpc()`'s first argument.
- **`types`** gives each function's positional `params`, how many trailing
  ones are `optional`, a variadic `rest`, and its `result`, each a JSON Schema
  read from the PHP signature (`Support\HostTypes`). What PHP cannot promise
  the shape of — `array`, a model, a collection, `JsonSerializable` — is an
  empty schema, which is `unknown` (PHP writes it as `[]`). A `Carbon` is a
  `date-time` string; PHP's own classes, `DateTime` included, are left open.
- **`defs`** holds the data classes those schemas refer to by
  `"$ref": "#/defs/Name"`, one TypeScript interface each.

## Conformance

CI runs rsc-kit's conformance suite against this endpoint on every change
(`.github/workflows/ci.yml`). `tests/Conformance/ConformanceServiceProvider.php`
registers the `Conformance.*` functions and the `conformance-allow` and
`conformance-deny` guards the suite calls, the way an app would; to run it
locally:

```sh
vendor/bin/testbench rsc:host-manifest --print > /tmp/rsc-host.json
PHP_CLI_SERVER_WORKERS=4 vendor/bin/testbench serve --port=8125 --no-reload &
npx -y -p @rsc-kit/core rsc-kit-conformance \
  --endpoint http://127.0.0.1:8125/__rsc/host-call --secret test --manifest /tmp/rsc-host.json
```

The Pest suite covers the same contract from this side: POST the request
shape, assert the reply shape. Rendering is the engine's, and its tests live
with it.
