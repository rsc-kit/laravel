---
name: laravel-rsc-development
description: "Builds React Server Component pages in front of a Laravel app with rsc-kit — pages and layouts under resources/js/app, rpc() calls into app/Rsc, server actions in app/Rsc/Actions, middleware.ts guards, forms, Suspense slots, and the dev and production setup."
license: MIT
metadata:
  author: rsc-kit
---

# rsc-kit for Laravel

## When to Apply

Activate this skill when:

- Creating or changing a page, layout or component under `resources/js/app`
- Reading Laravel data from a page with `rpc()`
- Writing a server action, a form, or a button that calls an action
- Guarding a route with `middleware.ts`
- Deciding where a Suspense boundary goes, or why the build refused a route
- Setting up development, tests or a production deploy

## The Model

The renderer is the front door: a JavaScript process built by Vite with
`@rsc-kit/core` and Nitro. It routes, renders, prerenders and serves the
assets. Laravel answers what only Laravel can, on one private endpoint
(`/__rsc/host-call`):

- the data a page reads, through `rpc()`
- the server actions a client component calls
- whether a route may render, through `middleware.ts`

Every Laravel route still works: `/login`, a Blade page, a webhook or a file
under `/storage` is forwarded to Laravel. Per url, **if the React tree has it,
React renders it**; otherwise Laravel does.

There is no PHP-side rendering, no socket, no `route.php` and no worker for
PHP to supervise. Do not write any of those.

## Where Things Live

```
resources/js/app/            the route tree
├── layout.tsx               root layout
├── page.tsx                 /
├── orders/
│   ├── page.tsx             /orders
│   ├── orders.section.tsx   a region an action can refresh by name
│   └── middleware.ts        Laravel middleware for /orders and below
├── [team]/                  dynamic segment: params.team
├── [...path]/               catch-all
├── (marketing)/             route group, no url segment
├── @modal/                  parallel route slot
├── error.tsx                must be "use client"
├── not-found.tsx            the app's 404 page
└── loading.tsx              rarely; see below
resources/js/server-actions.generated.ts   written by the build; never edit
app/Rsc/                     classes rpc() calls, Class.method
app/Rsc/Actions/             server actions
rsc-host.json                written by PHP: actions and function names
.rsc-kit/rsc-env.d.ts        declares rpc(), typed by those names
bootstrap/rsc/vite/          the build's generated code and route table
```

## Reading Laravel Data

`rpc()` is a global the renderer installs. Never import it. It works in
server components during a render, and nowhere else.

```php
// app/Rsc/Orders.php — discovered by convention
namespace App\Rsc;

class Orders
{
    public function __construct(private OrderRepository $orders) {}

    public function recent(int $limit = 5): array
    {
        return $this->orders->forUser(auth()->user())->latest()->take($limit)->get()->all();
    }
}
```

```tsx
const orders = await rpc<Order[]>('Orders.recent', 5)
```

- The name is `Class.method`; an invokable class is reached by its class name.
- The call runs as the visitor: their cookie is forwarded, so `auth()->user()`
  is them. The class is resolved through the container.
- The call is typed from the PHP signature, through `rsc-host.json`:
  `rpc('Orders.find', 7)` is the method's return type, and a wrong name or
  argument fails the typecheck. Make a class with
  `php artisan make:rsc-action Orders --rpc --method=recent`.
- Typed: `int`, `float`, `string`, `bool` parameters (nullable, defaults
  optional, variadic); a `FormRequest` first parameter from its `rules()`; a
  result that is a scalar, a backed enum, or a plain class with public typed
  properties. **Return a data object rather than an array** to type the
  result. `array`, models and collections stay `unknown`: write
  `rpc<Order[]>('Orders.recent')` for those.
- Sibling `rpc()` calls in one render go to Laravel as one batch, answered as
  each finishes.
- A client component cannot call `rpc()`. It calls a server action.

## Pages Paint; Slots Wait

This is the default way to write a page. The page is a **synchronous**
component: its headings, copy and frames are the stored shell, painted at
once. Each `rpc()` read goes in its own async child, a "slot", under its own
`<Suspense>`, with a skeleton the shape of what it replaces:

```tsx
import { Suspense } from 'react'

export default function OrdersPage() {
  return (
    <section>
      <h1>Orders</h1>
      <Suspense fallback={<OrdersSkeleton rows={5} />}>
        <OrdersSlot />
      </Suspense>
    </section>
  )
}

async function OrdersSlot() {
  const orders = await rpc<Order[]>('Orders.recent', 5)

  return <OrderList orders={orders} />
}
```

A fast slot is not held back by a slow one, and each slot does its own read
and its own checks, so it can move or be refreshed on its own.

**Do not reach for `loading.tsx`.** It wraps the whole page in one boundary:
the heading waits with the data, the slowest read holds back the rest, a 404
or redirect decided under it may already have a 200 on the wire, and it sits
below its layout, so it does not cover a layout that waits. Keep it for a page
that is one read and nothing else.

The build renders every route. A page that awaits `rpc()` above every
boundary has nothing to store, and the build fails naming the route. Fix it by
moving the read into a slot.

## Params

A page and a layout each receive `params` as a **promise**. A layout gets its
own segments' params and those above it. A route that lists no urls is stored
as one shell for every value, so a layout reads params under `<Suspense>`:

```tsx
export default function TeamLayout({ params, children }) {
  return (
    <>
      <Suspense fallback={null}>
        <TeamName params={params} />
      </Suspense>
      {children}
    </>
  )
}

async function TeamName({ params }) {
  const { team } = await params

  return (await rpc<Team>('Teams.find', team)).name
}
```

## Refusing

Attributes on a class or method, and the refusal reaches the page as itself:

```php
use RscKit\Attributes\Authenticated;
use RscKit\Attributes\Can;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Authenticated]
#[Middleware('throttle:60,1')]
class Orders
{
    #[Can('update', Order::class)]
    public function cancel(int $id): void { /* … */ }
}
```

| in PHP | the page gets |
| --- | --- |
| `AuthenticationException` | 401 |
| `AuthorizationException` | 403 |
| `ValidationException`, or a FormRequest that fails | `validationErrors` on the form |
| `abort(404)` | the app's `not-found.tsx` |
| a middleware `abort(429)` | its own status |
| `RscRedirectException` | a redirect the browser follows |

A status only reaches the response when it is decided before anything is
sent. A 404 from a read inside a slot shows the not-found page, but the
response may already be a 200. Decide whether a page exists in
`middleware.ts`.

## Route Middleware

`middleware.ts` names Laravel middleware for its directory and everything
below. It runs on every path, before anything renders, including a page the
build stored:

```ts
// resources/js/app/admin/middleware.ts
export const middleware = ['auth', 'verified', 'can:update,post']
```

It fails closed: only a pipeline that reaches the end lets the page render. A
middleware that aborts, redirects or errors refuses it. Never put an access
check in a layout: a navigation skips layouts the browser already holds.

## Server Actions

A class under `app/Rsc/Actions/` is a server action, exported to the app as
`classMethod`:

```sh
php artisan make:rsc-action Orders --method=cancel --auth --can=update,Order --revalidate=orders
```

```php
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

```tsx
'use client'
import { ordersCancel } from '../../server-actions.generated'
```

- `make:rsc-action` writes the guards the registry reads; do not hand-write
  them from memory. It also rewrites `rsc-host.json`, and a running dev
  server restarts, so the export is importable at once.
- A class you write by hand is picked up when Vite next starts, because
  `vite.config.ts` runs `php artisan rsc:host-manifest`.
- `Rsc::revalidate('orders')` names a region that changed: an
  `orders.section.tsx`, a slot, `'page'` or `'all'`. The re-rendered region
  comes back with the action's answer. A name the calling page does not show
  is skipped.
- A redirect from an action renders the destination fresh, so a cookie or
  membership the action changed is already reflected. No revalidate needed
  before it.
- `Rsc::changed("team:$teamId:repos")` is for a change that is not an
  action's answer: a webhook, a job, a listener, another user. A section
  that declared the name - `section('repos', Repos, { refreshOn: ({ params }) =>
  [`team:${params.team}:repos`] })` - refreshes in every open tab, with
  nothing polling. Versions live in the cache; with several servers, use a
  store they share (`rsc.versions_store`).

## Forms

```tsx
'use client'
import { Form } from '@rsc-kit/core/form'
import { ordersCreate } from '../../server-actions.generated'

export function NewOrder() {
  return (
    <Form action={ordersCreate}>
      {({ pending, error, formError }) => (
        <>
          <input name="title" />
          {error('title') && <p>{error('title')}</p>}
          {formError && <p role="alert">{formError}</p>}
          <button disabled={pending}>Create</button>
        </>
      )}
    </Form>
  )
}
```

- `error('field')` is the field's message. `formError` is a refusal that is
  not about a field.
- `optimistic={(data) => addOptimistic(data)}` pairs with React's
  `useOptimistic`; a failure takes it back.
- `resetOnSuccess` is on by default.
- From a button rather than a form, use `useAction` from
  `@rsc-kit/core/useAction`: `execute`, `isPending`, `onSuccess`, `onError`
  and an `optimistic` option.

## Environment

- A browser-readable variable starts with `VITE_` and is read as
  `import.meta.env.VITE_…`. There is no `process` in the browser, and the
  build refuses a `PUBLIC_*` variable.
- `RSC_HOST_CALL_SECRET` and `APP_URL` (or `RSC_BACKEND`) are read by both
  Laravel and the renderer from the app's `.env`.

## Commands

```sh
php artisan rsc:install          # config, secret, and the JavaScript half
php artisan make:rsc-action ...  # an action, or --rpc for an rpc() class
php artisan rsc:host-manifest    # rsc-host.json; Vite runs it for you
php artisan optimize             # includes rsc:cache, the discovered callables
npm run dev                      # Vite is the renderer
npm run build                    # .output/
npx rsc-kit-typegen && npx tsc --noEmit   # route types and rpc() names, then the typecheck
```

## Development

Serve the app through Herd, Valet or PHP-FPM and open its own address. `npm
run dev` writes `public/rsc-hot`, and Laravel hands every url it does not
route to the dev server.

`php artisan serve` has one worker by default and cannot do this: the
proxying request holds it while the page's `rpc()` calls need another. Use
`PHP_CLI_SERVER_WORKERS=4` in `.env` with `php artisan serve --no-reload`, or
open the renderer's address directly.

## Testing

- PHP: Pest, against the callables and actions as ordinary classes.
- JavaScript: `createTestApp` from `@rsc-kit/core/testing` builds the app and
  fetches pages with no server. Laravel's side is answered in the test:

```ts
import { createTestApp, hostReply, HOST_MIDDLEWARE } from '@rsc-kit/core/testing'

const app = await createTestApp({
  host: {
    'Orders.recent': () => [{ id: 1, number: 'A-1' }],
    [HOST_MIDDLEWARE]: ({ headers }) =>
      headers.get('cookie')?.includes('laravel_session') ? true : hostReply.unauthenticated(),
  },
  backend: (request) => new Response('the login page'),   // urls Laravel serves
})
```

`hostReply` also has `unauthorized()`, `redirect(to)`, `refuse(status,
message)`, `invalid(errors)` and `revalidating(result, ...regions)`.

## Production

```sh
composer install --no-dev --optimize-autoloader
npm ci && npm run build
```

- The build runs `rsc:host-manifest`, so PHP must boot on the build machine.
- Run `.output/server/index.mjs` as a service with the app's `.env`. Put the
  renderer in front; it forwards what it does not own to Laravel.
- Block `/__rsc/host-call` from the internet at the web server. The secret
  is required; without one the endpoint is not registered.
- Add the renderer to `trustProxies`, so `url()` and redirects use the public
  origin.

## Do Not

- Import `rpc`, or call it from a client component.
- Write `route.php`, a socket, `rsc:serve`, or anything that renders in PHP.
- Edit `server-actions.generated.ts` or `rsc-host.json` by hand.
- Add `loading.tsx` to fix a refused route; move the read into a slot.
- Check access in a layout; use `middleware.ts`.
- Use the old `laravel-rsc/form` or `rscRoutes` imports; it is
  `@rsc-kit/core/form` and `rscKit` from `@rsc-kit/core/vite`.
