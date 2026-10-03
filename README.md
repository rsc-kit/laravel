# rsc-kit for Laravel

React Server Components with Laravel behind them. The renderer owns the request
— routing, rendering, prerendering, static serving — and calls back here for
the data, the session, and whether a route may render at all.

This is the Laravel backend for [rsc-kit](https://github.com/rsc-kit/rsc-kit).
The engine is `@rsc-kit/core` on npm and is backend-agnostic; this package is
about 400 lines of PHP that answers its questions.

```sh
composer require rsc-kit/laravel
```

## What you get

- **React Server Components** — server-rendered React, no client JS for a server component
- **File-based routing** — the file tree is the route table; no routes to declare
- **PHP callables** — reach Eloquent, auth and sessions from a server component with `rpc()`, typed from the PHP signature
- **Server actions** — a class under `app/Rsc/Actions` becomes a `"use server"` function the browser calls, with your validation
- **Your middleware, per route** — `auth`, `verified`, `throttle:60,1`, `can:update,post`, run through Laravel's own pipeline
- **Streaming HTML** — Suspense boundaries fill in progressively
- **Partial prerendering** — a static shell at build time, the rest streamed
- **Parallel routes and interception** — `@folder` slots, `(.)` modals
- **Typed routes** — the build writes the urls it found, so a link to a page that does not exist fails the typecheck
- **Refresh on change** — a section names what it refreshes on; `Rsc::changed("team:$id:repos")` from a webhook or a job refreshes it in every open tab, with nothing polling Instant with Laravel's broadcasting (Reverb, Pusher, Soketi).

## How it fits together

Two processes. The renderer serves the browser; Laravel answers it.

```
browser  →  renderer  ──render──▶  React
                │
                ├─ before rendering:  POST /__rsc/host-call  {"function":"__rsc.middleware","args":[["auth"]]}
                └─ during rendering:  POST /__rsc/host-call  {"function":"Orders.recent","args":[5]}
                                              │
                                              ▼
                                        your Laravel app
```

Both are the same endpoint, and it is not registered until you give it a
secret (`RSC_HOST_CALL_SECRET`, sent by the renderer as `X-Rsc-Host-Secret`).
It runs registered functions by name with none of your routing in front of
it, so keep it unreachable from outside as well as authenticated: restrict
the path at the web server, or bind it to loopback. The wire format is in
[PROTOCOL.md](PROTOCOL.md).

There is no PHP-side rendering, no socket and no process for PHP to
supervise. Vite is the renderer in development; `vite build` writes a Nitro
server to `.output/` for production. Either process can face the browser:
the renderer forwards urls it does not own to Laravel, and Laravel hands
what it does not route to the renderer (in development through
`public/rsc-hot`, or with `RSC_RENDERER_URL`). Per url, if the React tree
has it, React renders it; otherwise Laravel does.

## Quick start

```sh
composer require rsc-kit/laravel
php artisan rsc:install
```

`rsc:install` does the PHP half itself — publishes `config/rsc.php`, generates
`RSC_HOST_CALL_SECRET` and `RSC_SIGNING_SECRET` into your `.env` — and runs `rsc-kit init` for the
JavaScript half. Nothing you already have is overwritten: where a file exists,
the exact edit is printed for you to make instead.

A function your components can call — any public method of a class under
`app/Rsc`, named `Class.method`, resolved through the container:

```php
// app/Rsc/Posts.php
namespace App\Rsc;

class Posts
{
    public function latest(): array
    {
        return Post::latest()->take(5)->get()->all();
    }
}
```

A page that calls it:

```tsx
// resources/js/app/page.tsx
export default async function Home() {
  const posts = await rpc<Post[]>('Posts.latest');

  return (
    <main>
      {posts.map((p) => <article key={p.id}><h2>{p.title}</h2></article>)}
    </main>
  );
}
```

Guarding a route, without declaring one:

```ts
// resources/js/app/admin/middleware.ts
export const middleware = ['auth', 'can:update,post'];
```

Those are ordinary Laravel middleware, run through Laravel's own pipeline. The
renderer asks before anything at or below that route renders — a page frozen
at build time included — and a refusal is the answer to the request. It fails
closed: only a pipeline that reached its end lets the page render.

A server action, with its guards:

```sh
php artisan make:rsc-action Orders --method=cancel --auth --can=update,Order --revalidate=orders
```

```php
// app/Rsc/Actions/Orders.php
namespace App\Rsc\Actions;

use RscKit\Attributes\Authenticated;
use RscKit\Rsc;

class Orders
{
    #[Authenticated]
    public function cancel(CancelOrder $request): void
    {
        $request->order()->cancel();

        Rsc::revalidate('orders');
    }
}
```

A client component imports `ordersCancel` from the generated
`server-actions.generated.ts`. `Rsc::revalidate('orders')` sends the
re-rendered section back with the action's answer.

## Refusing

`#[Authenticated]` and `#[Can]` (`RscKit\Attributes`) and Laravel's own
`#[Middleware]` go on a class or a method. What is thrown reaches the render
as itself rather than as a broken page:

| thrown in PHP | the render gets |
| --- | --- |
| `AuthenticationException` | 401, the engine's authentication error |
| `AuthorizationException` | 403 |
| `ValidationException` | 422, each message under its input on the form that submitted |
| a middleware `abort()` | its own status — throttle's 429 stays a 429 |
| `RscRedirectException` | a redirect the browser performs |

Anything else is a failure: reported to Laravel's log and answered 500. With
`app.debug` on, the answer also carries the exception's class, message and
trace, and the renderer shows them under its own stack — so a failed `rpc()`
points at the line of PHP that threw. Never in production.

## Commands

| command | what it does |
| --- | --- |
| `rsc:install` | publishes `config/rsc.php`, generates both secrets, runs `rsc-kit init` for the JavaScript half (`--skip-js` for the PHP half only) |
| `rsc:prune-versions` | deletes `refreshOn` versions nobody changed in `rsc.versions_keep_days` (database mode); schedule it daily |
| `rsc:host-manifest` | writes `rsc-host.json` — `actions`, `functions`, and their `types` and `defs` from the PHP signatures. `vite.config.ts` runs it as dev and every build start (`rscKit({ hostManifest })`) |
| `make:rsc-action` | a server action under `app/Rsc/Actions`, or with `--rpc` a class for `rpc()` under `app/Rsc`; `--method`, `--auth`, `--can`, `--middleware`, `--revalidate`. Writes the manifest too |
| `rsc:cache` / `rsc:clear` | cache the discovered callables for production; run by `optimize` and `optimize:clear` |

## Requirements

- PHP 8.3+
- Laravel 13+
- [Bun](https://bun.sh) or Node 24+, for the renderer and the build
- Vite 8 — the plugin needs it, and a Laravel application ships an older one
- React 19
- `@rsc-kit/core` ^0.24 — this release's pairing (`RscKitServiceProvider::ENGINE_CONSTRAINT`); `rsc:install` checks the installed version

## Tests

`vendor/bin/pest --compact` covers discovery, the endpoint's contract and the
middleware runner. CI also runs rsc-kit's conformance suite against this
adapter on every change: `tests/Conformance` registers the functions it calls
the way an app would. Rendering is the engine's, and its tests live there.

## Documentation

Guides and the full API at **[docs.rsc-kit.dev/hosts/laravel](https://docs.rsc-kit.dev/hosts/laravel)**.

## Support

Issues and discussion at [rsc-kit/laravel](https://github.com/rsc-kit/laravel).
