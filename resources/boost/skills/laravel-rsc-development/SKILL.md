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
- Keeping a page current when data changes outside the tab - a webhook, a
  job, another user - instead of polling
- Deciding where a Suspense boundary goes, or why the build refused a route
- Setting up development, tests or a production deploy

## Rules

Follow these on every change; each is explained below.

- **A change is not done without its test.** Pest for the callables and actions
  under `app/Rsc` (their refusals included); `createTestApp` for pages. Run
  `php artisan test` and the JavaScript typecheck before saying it is done.
- **A new setting goes in `config/` and `.env.example`.** Read it with
  `config()`, never `env()` outside a config file - Laravel's rule, kept here.
- **Pages paint at once.** A page is synchronous; each `rpc()` read is an async
  component under its own `<Suspense>`. Not `loading.tsx` to hide a slow read.
- **Server components call `rpc()` directly** - never `fetch` the app's own
  Laravel routes from them.
- **Live data:** `refreshOn` on the section and `Rsc::changed()` where the data
  changes, after the transaction commits. `usePolling` only for data nothing
  can announce.
- **Authorisation lives in the callable** - `#[Authenticated]`, `#[Can]`, a
  policy - never only in the component that shows the button.
- **Links and redirects are typed:** ``<Link href={`/orders/${id}`}>``, a
  template literal, so a renamed page fails the typecheck.
- **Metadata is `export const metadata`**, never tags in a page's markup.
- **Never edit generated files:** `resources/js/server-actions.generated.ts`,
  `rsc-host.json`, `.rsc-kit/`. Change the PHP they are generated from.
- **Secrets are generated, never invented or committed:** `php artisan
  rsc:install` writes `RSC_HOST_CALL_SECRET` and `RSC_SIGNING_SECRET`.
- **Ask before guessing:** the `rsc-kit` MCP server's `rules` and `how_to`
  answer for the installed version.

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
│   ├── orders.section.tsx   a region an action, or Rsc::changed(), can refresh
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
| `ValidationException`, or a FormRequest that fails | `validationErrors` on the form in an action; 422 from a read in a page |
| `abort(404)` in a read | the app's `not-found.tsx`; in an action, the message as `formError` |
| a middleware `abort(429, 'Slow down')` | its own status; an action shows the message as `formError` |
| `Rsc::refuse('Still in use', ['blockers' => $links])` | the message as `formError`; the data as `result.refusal` only in an action built on `createActionClient()` with `.refusal(schema)`, 409 |
| `RscRedirectException` | a redirect the browser follows |

Whatever the backend turns a call down with reaches an action as its message:
`abort(429, 'Slow down')`, a 403, a 404, `Rsc::refuse()` with or without data.
Write the message for the visitor - without one it reads "Refused." - and do
not unwrap it in `onError`: only a real failure gets there, and it is replaced
by the app's generic message on purpose, so a query's SQL never reaches a form.
A read in a page answers the status itself, as above; `fetchQuery` rejects with
the message, `.status` and the refusal's `.refusal`.

**Through a generated stub**, `<Form action={ordersDelete}>` shows the message
as `formError`. Awaited directly, the stub **rejects** with an
`ActionRefusedError` (`@rsc-kit/core/errors`: `.message`, `.status`), so a
success toast or a navigation to an id that never came back does not run for a
refused write; `useAction` reports it as `serverError`. Only a redirect resolves.
An input Laravel refused (a `ValidationException`, a FormRequest that fails)
rejects with a `ServerValidationError` instead, with `.fieldErrors` and
`.formErrors` (a nested field dot-joined); `<Form>` shows the fields as it
always did and `useAction` returns them as `validationErrors`. One rule for a
stub: anything that did not happen rejects, and only a redirect resolves, so
`isRedirected` is the only check a caller needs on what came back.
A plain function has no `.refusal(schema)` to check data against, so the data is
left out and the renderer's log says so. To act on it, call `rpc()` from an
action built on `createActionClient()` and declare `.refusal(schema)`.

Refuse with data when the input is fine and the answer is still no, and the
page needs more than a sentence - what is blocking a delete, as links. Do not
encode the blockers into the message, and do not rebuild them from the page's
own list: the refusal knows what is blocking it at the moment of the write.

A status only reaches a person's response when it is decided before anything
is sent. A 404 from a read inside a slot shows `not-found.tsx` where the page
was - the layouts above it stay and the url is unchanged - the response stays
200, and the page ends with a `noindex` tag. An `error.tsx` never sees it, and
nothing should check for it.

A `not-found.tsx` beside a layout answers `notFound()` from every page under it,
inside that layout - the nearest one above the page wins, like `error.tsx` - so
`resources/js/app/(shop)/not-found.tsx` keeps the shop's header on a missing
product. A url no route owns is answered by the root one only. A search engine or
link-preview crawler is answered once the page has finished, so it gets the
real 404. When it must be a 404 for everyone, decide whether the page exists in
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

A query built from `createActionClient().input(schema)` takes that schema's
input, so a wrong key fails `tsc`. It cannot redirect - a cache library reads
it, nothing navigates - so refuse with `ServerAuthenticationError` (a 401) and
let the cache's error handler send the visitor to sign in.

The engine's server-only entries (`request`, `redirect`, `revalidate`, `cache`,
`section` and the like) are refused in the browser bundle: a build fails, and a
dev load, with "ended up in the browser bundle" and the import chain. The usual
cause is a helper file that a page and a client component both import - split
it, so what calls `redirect()` is only imported by server components. Mark your
own server-only files with `import 'server-only'`; the build honours it.

A page never answers a request the browser marks as an image, script,
stylesheet or font (`Sec-Fetch-Dest`): a dynamic `/[team]` route gets a plain
404 for `/favicon.ico` before its middleware or any `rpc()` runs. A `route.ts`
still answers one, and a navigation gets the page.

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
- A call to a generated stub that was redirected (an `RscRedirectException`,
  or a guard sending an expired session to `/login`) resolves with
  `{ redirected }`, not the method's return value; the stub is typed
  `Promise<T | Redirected>`. Narrow with `isRedirected` from
  `@rsc-kit/core/errors` before reading the value, and before any success toast
  after an `await` of a void stub. `<Form>` and `useAction` already skip
  `onSuccess` for it. `tsc` does not see a redirect read as text
  (`` `/t/${id}` `` goes to `/t/[object Object]`): the project's oxlint runs
  `typescript/restrict-template-expressions`, `no-base-to-string` and
  `restrict-plus-operands` with `--type-aware`.
- An action can log someone in: `Auth::login()` (or any `Cookie::queue`)
  sets the session cookie on the action's answer, and the renderer puts it
  on the page's response. Redirect after it as usual. A callable read inside
  a page cannot - its response headers have gone - so cookies are set from
  actions.
- A change that did not come from the tab - a webhook, a job, another user -
  is `Rsc::changed()`, below.

## Refreshing on Outside Changes

`Rsc::revalidate()` answers the tab that ran the action. For a change made
anywhere else - a webhook, a queued job, a listener, another user - a
section names what it depends on, and PHP says when that changed. Every
open tab showing it refreshes, with nothing polling and no client code.

```tsx
// resources/js/app/t/[team]/repos.section.tsx
import { section } from '@rsc-kit/core/section'

export default section('repos', async function Repos({ params }) {
  const { team } = await params
  const repos = await rpc('Repos.list', team)

  return <ul>{repos.map((r) => <li key={r.id}>{r.name}</li>)}</ul>
}, { refreshOn: ({ params }) => [`team:${params.team}:repos`] })
```

```php
// a webhook controller, a job's handle(), a listener
Rsc::changed("team:{$team->id}:repos");
```

- `refreshOn` is a list of names, or a function of the page's `params` and
  `searchParams` - given to it already awaited, so
  `({ params }) => [\`team:${params.team}:repos\`]` is right. It runs per
  request, so `cookies()` works: a name for the signed-in user is fine.
- A name is a signal, not data or a permission. A refresh renders for its
  own visitor, through their session, guards and rpc() calls, so per-user
  data stays per user: a chat's `conversation:{id}` refreshes both people's
  tabs, and each sees their own view. A tab can only listen for names its
  page rendered with. Use ids in names - never emails or anything secret. A page can `export const refreshOn` too; a change
  refreshes the page.
- A page's own `refreshOn` is typed from its url schema, so `params` arrive
  parsed - no casts:

  ```tsx
  import type { PageRefreshOn } from '@rsc-kit/core/section'

  export const params = z.object({ team: z.string() })
  export const refreshOn: PageRefreshOn<typeof params> = ({ params }) => [`team:${params.team}`]
  ```

  Without a schema, name the route's pattern - `PageRefreshOn<'/t/[team]'>` -
  and each param is a string; a misspelt one fails the typecheck.
  `export const`, `export function` and a list - `export { refreshOn } from
  './names'` - all count. `export * from` does not: name it in a list.
- Name what the data is, not where it shows: `team:{id}:repos`,
  `deploy:{id}`, `order:{id}`. Two sections on the same name both refresh.
- A change to a child is a change to its parent's list. Say both where the
  write happens - `Rsc::changed("app:{$app->id}", "team:{$app->team_id}:apps")`
  - and let each region name only what it shows. Never make a list watch a
  name per row: it misses a row added after it rendered.
- Call `Rsc::changed()` after the write is committed - in a transaction,
  `DB::afterCommit(fn () => Rsc::changed(...))` - or a tab can refresh
  before the data is there to read.
- Inside an action, keep `Rsc::revalidate()` for the caller's own tab and add
  `Rsc::changed()` for everyone else's.
- `shared: true` on a section that is the same for everyone allowed to see
  the page: tabs refreshing because of the same change get one render. Never
  on anything per visitor - `rpc()` calls run with the first tab's session.
- Versions live in the cache by default: use a store every server shares
  (Redis, database), set with `RSC_VERSIONS_STORE` when it is not the
  default. The renderer asks PHP which moved every couple of seconds while
  any tab is watching.
- Keep the cache default. Watching costs one small PHP request about every
  two seconds per renderer process, for all its tabs - not per tab - and
  Laravel stays the only thing that talks to its database.
- `RSC_VERSIONS=database` (publish `rsc-migrations`, migrate) is for pruning
  versions on a schedule, or for writers outside Laravel. Laravel still
  answers the renderer from the table.
- For instant updates, use broadcasting, not the database: with Laravel's
  broadcasting set up (Reverb, Pusher, Soketi), set `RSC_BROADCAST=true`, and
  give the renderer `RSC_BROADCAST_URL` and `RSC_BROADCAST_KEY`. Each
  `Rsc::changed()` announces a change (no names on the channel), the
  renderer asks Laravel at once, and otherwise asks only every 30 seconds.
- Do not have the renderer read Laravel's database: it would need the
  credentials, a driver and the table's layout.
- With `RSC_VERSIONS=database`, schedule `rsc:prune-versions` daily: a row
  is kept for every name that ever changed. Deleting is always safe (a
  version is a time and never repeats). Cache keys expire on their own
  after `RSC_VERSIONS_KEEP_DAYS` (30).
- In development the browser console lists what each region watches. A
  region missing there rendered no names; the renderer's log says why - a
  `refreshOn` that gave no names at all is said too, usually a param read
  under the wrong name.
  Hidden tabs stop watching and catch up when shown.

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

- `error('field')` is the field's message, and its names are checked: they are
  read off the stub's typed parameter (the PHP signature, already in
  `rsc-host.json`), so `error('titel')` fails `tsc`. Nested paths work
  (`address.city`, `items.0.sku`). Open when the action says nothing. A
  component below the form types its prop with `FieldNamesOf<typeof action>`
  from `@rsc-kit/core/form`. A wrapper you write around a stub (a function of a
  `FormData`) declares its fields on its parameter, `(form: FormFields<'id' |
  'name'>)`, and `error()` closes to them. A form fills only the **first**
  parameter of a stub, so a stub of plain strings is never a form's action. `formError` is a refusal that is
  not about a field. `formRefusal` is the data a refusal carried, typed from the
  form's action when it was built on `createActionClient().refusal(schema)` -
  no cast.
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
- `RSC_SIGNING_SECRET` is the renderer's, for `refreshOn`: a long random
  string, the same on every instance. It is not the host-call secret and
  does not fall back to it. In production, an app that uses `refreshOn`
  without it refuses to serve.
- `RSC_STREAM_KEEPALIVE_MS` (the renderer, default 8000): set it below the
  idle timeout of anything in front that drops quiet connections, or open
  tabs keep reconnecting.
- `RSC_BROADCAST` (Laravel), `RSC_BROADCAST_URL` and `RSC_BROADCAST_KEY` (the
  renderer): announce each change on Laravel's broadcasting so tabs hear it
  at once. `RSC_BROADCAST_CHANNEL` renames the channel.
- `RSC_VERSIONS` (`cache` or `database`), `RSC_VERSIONS_STORE`,
  `RSC_VERSIONS_TABLE`, `RSC_VERSIONS_CONNECTION`: where `Rsc::changed()`
  keeps versions. `RSC_VERSIONS_KEEP_DAYS` (30): how long an unchanged name
  is kept.

## Commands

```sh
php artisan rsc:install          # config, both secrets, and the JavaScript half
php artisan make:rsc-action ...  # an action, or --rpc for an rpc() class
php artisan rsc:host-manifest    # rsc-host.json; Vite runs it for you
php artisan rsc:prune-versions   # delete refreshOn versions nobody changed in 30 days
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
message)`, `invalid(errors)`, `revalidating(result, ...regions)` and
`settingCookies(result, ...cookies)`.

For `refreshOn`, `testChanges()` answers the renderer's version asks the way
Laravel does, and its `changed()` stands in for `Rsc::changed()`:

```ts
import { testChanges } from '@rsc-kit/core/testing'

const changes = testChanges()
const app = await createTestApp({ host: { ...changes.host, 'Repos.list': () => repos } })

changes.changed('team:1:repos')   // what the webhook would say
```

`app.watched(path)` says what each region of a page refreshes on, for that
url - `page` for the page's own, a section's name for each section's - so a
test checks the names without reading the payload:

```ts
expect(await app.watched('/t/acme')).toEqual({ page: ['team:acme'], repos: ['team:acme:repos'] })
```

`hostReply.settingCookies(result, 'laravel_session=...')` stands in for a
callable that logs someone in.

`app.markup(path)` is the page with every `<script>` taken out. Assert on it,
not on `app.fetch()`'s body: the document carries the page's own payload, so
text that is not on screen is still in the raw HTML.

A client component that calls a stub is tested by mounting it, with happy-dom
and `act`, and replacing the module the stubs come from. Register the DOM from a
module the test file imports **first** (`import './dom'`), not in `beforeAll`:
a library such as Base UI checks for a DOM when it is imported and keeps the
answer. Never register it in a global preload - it replaces `Request` and
drops the `Cookie` header, and `createTestApp` refuses to run while one is
registered. Run `bun test --isolate`, since `mock.module` is process-wide. The
test that matters is the one where the stub resolves `{ redirected }` and no
success is shown.

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
- With `refreshOn`: set `RSC_SIGNING_SECRET` on every renderer instance, and
  keep versions where every server reads them - a shared cache store, or
  `RSC_VERSIONS=database`.

## Do Not

- Import `rpc`, or call it from a client component.
- Write `route.php`, a socket, `rsc:serve`, or anything that renders in PHP.
- Edit `server-actions.generated.ts` or `rsc-host.json` by hand.
- Add `loading.tsx` to fix a refused route; move the read into a slot.
- Check access in a layout; use `middleware.ts`.
- Poll with `usePolling` for data the backend can announce: `refreshOn` plus
  `Rsc::changed()`.
- Put `shared: true` on a section that shows anything per visitor.
- Reuse `RSC_HOST_CALL_SECRET` as `RSC_SIGNING_SECRET`; they are two keys
  for two jobs.
- Use the old `laravel-rsc/form` or `rscRoutes` imports; it is
  `@rsc-kit/core/form` and `rscKit` from `@rsc-kit/core/vite`.
