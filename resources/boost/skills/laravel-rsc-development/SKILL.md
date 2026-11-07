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
- **Metadata is `export const metadata`**, never tags in a page's markup. A
  page's own share image is a file beside it, not a tag:
  `resources/js/app/pricing/opengraph-image.png` (and `twitter-image.png`) is
  the card for `/pricing` and the pages under it, the nearest folder winning -
  a route group such as `(marketing)` counts as a folder, and `[slug]/` covers
  every page it answers. A page that exports `openGraph.images` replaces the
  site's image rather than adding a second `og:image` behind it; the nearer of
  the file and the export wins, and in the same folder the file does.
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

A dynamic route at the root, `[team]/[app]`, has every url of two segments, so
with the renderer in front it also has Laravel's `/auth/github` and `/login/verify`:
it renders, says `notFound()`, and a page that said that is not handed to Laravel
(sign-in loops). Name the prefixes Laravel answers in `vite.config.ts`,
`rscKit({ backendPaths: ['/auth', '/login'] })`, and they are forwarded before
any page is asked - a prefix at a segment boundary, every method. A team called
`auth` is then unreachable. With Laravel in front your own routes already win.
Do not write catch-all `route.ts` files that forward by hand.

A url named there is never a page in the browser either: a `<Link>`, `visit()`
or `redirect()` to `/auth/github` loads the document and is never prefetched, so
a Laravel route that bounces on to GitHub works from a click and a hover does
not start the OAuth flow. Unnamed, the router fetches it as a page, the bounce
fails and nothing happens on the tap. The prefixes are written into
`rsc-routes.d.ts`, so `redirect('/auth/github')` needs no cast (`/authors` is
still refused). A redirect to another site works as written.

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

An `error.tsx`'s `reset()` asks the server for the page again (the layouts
stay) and shows what it answers, so a "Try again" button recovers once a Laravel
call that failed for a moment succeeds. It is the whole retry: no `refresh()`
beside it and no reload.

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
  is skipped. A page shows the sections beside it, then the ones beside its
  layouts, nearest first - so what every tab shares, a header or a status,
  lives in a section next to the layout and is refreshed by name from any
  page under it. Give it its own `<Suspense>` when it reads `params`, pass
  `params` through, and mark the active tab with `usePathname()`, not a
  refresh.
- A redirect from an action keeps the layouts as they were, like a link -
  right for save-then-list. When the action changed what a layout reads (a
  team switch, a sign-in, a count in the sidebar), call
  `Rsc::revalidate('all')` before the redirect, so the destination comes back
  whole, layouts included (`revalidate('all') before` the `redirect()` in a
  JS action). Without it the page is new and the sidebar stale until a reload.
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
- A message for the next page ("Invoice sent." after a redirect): call
  `Rsc::flash('Invoice sent.')` in the action - or `Rsc::flash('Declined.',
  'error')`; the kinds are success, error, info and warning - and render one
  client component in the root layout that calls
  `useFlash(({ message, kind }) => toast(message))` from
  `@rsc-kit/core/useFlash`. It shows once, and not again on reload. It is a
  cookie, so from an action or a controller, never a callable a page reads.
  The browser reads it, so the package leaves it unencrypted: nothing secret
  goes in a flash.
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

async function ReposList({ params }) {
  const { team } = await params
  const repos = await rpc('Repos.list', team)

  return <ul>{repos.map((r) => <li key={r.id}>{r.name}</li>)}</ul>
}

// Named, then exported: an editor offers an import by the name a default
// export has, and `export default section(...)` has none.
// The name is the file's - repos.section.tsx is section('repos').
// Always name it after its file, or the build fails.
const Repos = section('repos', ReposList, {
  refreshOn: ({ params }) => [`team:${params.team}:repos`],
})

export default Repos
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
- A change made straight in the database - `psql`, a migration, an admin tool,
  another app - says nothing, because nothing watches a table: open tabs stay
  stale until refreshed (a reload reads fresh; the data is not wrong). Make the
  writer call `Rsc::changed()` after the commit, or, for writes that never pass
  through PHP, a trigger on the data table that upserts the `rsc_versions` row
  in the same transaction - and `pg_notify('rsc_versions', '')` on Postgres, so
  tabs hear at once. `usePolling` only where nothing can say it.
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
  'name'>)`, and `error()` closes to them. A team-scoped action,
  `func(ctx, team, in)`, is a form's action once the team is bound:
  `const create = stub.bind(null, team)` (a `const`; bound inline the names are
  lost), because a form fills the struct parameter after whatever was bound. A
  stub whose parameters are all plain strings has no struct to fill - wrap it. A
  form that posts nothing names its fields on the form, `<Form fields={['size']}>`. `formError` is a refusal that is
  not about a field. `formRefusal` is the data a refusal carried, typed from the
  form's action when it was built on `createActionClient().refusal(schema)` -
  no cast.
- A search box is a GET form whose `action` is a url, `<Form action="/domains">`.
  When that is the page on screen, a search on the page it is on keeps the page
  up, old results and all, until the new ones land, and `pending` is true until
  they have - no shell, no skeleton. Do not wrap it in your own state or a
  Suspense `key`. To draw a skeleton where the results are, style them from the
  form's `data-pending` attribute. A `<Link>` to the same path with another
  query is still a page of its own.
- `optimistic={(data) => addOptimistic(data)}` pairs with React's
  `useOptimistic`; a failure takes it back.
- `resetOnSuccess` is on by default.
- From a button rather than a form, use `useAction` from
  `@rsc-kit/core/useAction`: `execute`, `isPending`, `onSuccess`, `onError`
  and an `optimistic` option.
- No CSRF token, no `@csrf`, no `csrf_token()` in an `rpc()` call. A form, an
  action and Laravel 13 itself accept a same-origin post by the browser's
  `Sec-Fetch-Site` header. A `POST`, `PUT`, `PATCH` or `DELETE` to a
  `route.ts` that a browser sends from another site is answered 403 before the
  handler runs; a webhook or a server sends neither header and is never
  refused. Another origin that should post is named with
  `rscKit({ trustedOrigins: ['https://admin.example.com'] })`.

## Admin Panels

`@rsc-kit/press` builds an admin panel from resources, and a theme draws it:
`shadcn` from `@rsc-kit/press/shadcn` (sidebar, data table, forms, dialogs).
There is no `@rsc-kit/admin` any more - do not import it. It needs Tailwind v4
and shadcn, and the app's stylesheet imports the theme after them:
`@import "@rsc-kit/press/shadcn.css";` - without it the panel is unstyled. The
panel runs in the renderer; Laravel keeps the data, the rules and who may see
it.

```ts
// resources/js/admin/resources/products.ts
import { defineResource, type DataSource } from '@rsc-kit/press'
import * as z from 'zod'

const form = z.object({ name: z.string().min(2), sku: z.string(), price: z.number().min(0) })
type Product = { id: number; name: string; sku: string; price: number }

const source: DataSource<Product, z.infer<typeof form>> = {
  list: (query) => rpc<{ rows: Product[]; total: number }>('Products.list', query),
  find: (id) => rpc<Product | null>('Products.find', Number(id)),
  create: (values) => rpc<Product>('Products.create', values),
  update: (id, values) => rpc<Product>('Products.update', { ...values, id: Number(id) }),
  delete: (id) => rpc('Products.delete', Number(id)),
}

export const products = defineResource<Product>()({
  name: 'products', key: 'id', title: 'name', source, form,
  table: (col) => [col.text('name').searchable().sortable(), col.text('sku'), col.money('price').sortable()],
})
```

```php
// app/Rsc/Products.php
public function update(UpdateProduct $request): Product
{
    $product = Product::findOrFail($request->integer('id'));
    $product->update($request->validated());

    return $product;
}
```

- The form is any Standard Schema - zod, valibot, ArkType. Its fields are read
  from the schema's Standard JSON Schema; one without it lists them in
  `fields`. A number field arrives as a number: write `z.number()`, not
  z.coerce.number().
- Checked in the browser first: the schema's constraints become `required`,
  `min`, `maxLength`, `pattern` and so on, with the message in shadcn's
  `FieldError`. The schema then runs on the server, and Laravel's FormRequest
  after it. Laravel's answer is the one that counts: a rule the schema cannot
  know (`Rule::unique('products', 'sku')->ignore($this->integer('id'))`)
  comes back as a 422, and its messages land under the same fields. Nothing to
  catch - let the `rpc()` call throw.
- A FormRequest method receives the request alone - no argument after it - so
  `update` sends the id inside the values, as above.
- `list` is told `{ q, sort, dir, page, perPage, filters }`. Allowlist `sort`
  before it reaches `orderBy`. A resource with `mode: 'client'` is listed once
  with `perPage: null` and must return every row; the browser searches, sorts
  and pages. `find` answering `null` is a 404.
- Every call's last argument is the context `{ tenant }`, null without tenancy.
  Pass `tenant.id` on to the PHP side; do not trust a team id from the form.
- `authorize: () => rpc<boolean>('Admin.allowed')` runs on every page and on
  every save, delete and team switch, because the panel's actions are reachable
  without its pages. A refusal is a 404. Laravel middleware names in the same
  `middleware.ts` run first, but they guard pages only, so the panel still needs
  `authorize`.
- `tenancy: { tenants, current, switch }` adds a team switcher. `current` reads
  the session, the panel refuses one that is not in `tenants()`, and `switch`
  runs in an action, so `rpc('Teams.switch', id)` may write the session.
- The panel is `definePanel` from `@rsc-kit/press/admin`, with the theme:

```ts
// resources/js/admin/panel.ts
import { definePanel } from '@rsc-kit/press/admin'
import { shadcn } from '@rsc-kit/press/shadcn'

export const panel = definePanel({
  path: '/admin',
  theme: shadcn,                       // { ...shadcn, Dashboard: Mine } replaces one page
  resources: [products],
  authorize: () => rpc<boolean>('Admin.allowed'),
})
```

- Mount it with seven files under `resources/js/app/admin/`
  (`resourceParams`, `recordParams` and `listSearch` come from
  `@rsc-kit/press/admin`). The section must
  be named `admin-table.section.tsx`, because a delete refreshes it by that name:

```
middleware.ts                      export const middleware = ['auth']
                                   export default panel.middleware
layout.tsx                         export default panel.Layout
page.tsx                           export default panel.Dashboard
[resource]/page.tsx                export const params = resourceParams
                                   export const searchParams = listSearch
                                   export default panel.Index
[resource]/admin-table.section.tsx export default panel.Table
[resource]/new/page.tsx            export const params = resourceParams
                                   export default panel.Create
[resource]/[id]/page.tsx           export const params = recordParams
                                   export default panel.Edit
```

- The header has a Light / Dark / System menu, System by default. The root
  layout's `<html>` needs `suppressHydrationWarning`, because the theme is set
  before React hydrates.
- Saves and deletes say so in a toast on the page the visitor lands on.
- A belongsTo is `fields: { categoryId: { relation: 'categories' } }`: a select
  of the categories resource's rows, its key saved and its title shown, and
  `col.relation('categoryId', 'categories')` shows the title in the table. The
  choices are that resource's `list` with `perPage: null`, as the visitor, so
  relate to a resource with a select's worth of rows. The posted id is checked
  with that resource's `find` before the save, so the PHP side gets an id the
  visitor can see; still scope it to the tenant there.
- An enum's options read humanized (`in_stock` is "In stock") and save as they
  are; `options: [{ value: 'active', label: 'Live' }]` labels them yourself.
- Rows can be ticked for the panel's own Delete and the resource's
  `bulkActions: [{ name: 'publish', label: 'Publish', run: (rows, context) =>
  rpc('Products.publish', rows.map((row) => row.id), context.tenant?.id) }]`.
  `run` gets rows found as the visitor, after `authorize`; `destructive: true`
  asks first.
- A plugin (`definePlugin`) adds resources and an `onChange` that runs after a
  write and never fails it. Publish one with `@rsc-kit/press` as a peer and the
  `rsc-kit-admin-plugin` keyword.

## Public Forms, Pages and Uploads

The rest of `@rsc-kit/press`, drawn by the same theme.

**A public form** (contact, sign-up) is `defineForm` from
`@rsc-kit/press/forms`, placed as `<contact.Form />` in a page that stays
static - no `connection()`, no Suspense for it:

```ts
// resources/js/forms/contact.ts
import * as z from 'zod'
import { defineForm } from '@rsc-kit/press/forms'
import { shadcn } from '@rsc-kit/press/shadcn'

export const contact = defineForm({
  name: 'contact',
  form: z.object({ name: z.string().min(2), email: z.email(), message: z.string().min(10) }),
  fields: { message: { type: 'textarea' } },
  theme: shadcn,
  done: 'Thanks - we will be in touch.',  // or redirect: '/thanks', never both
  handle: (values) => rpc('Contact.send', values),
})
```

- The schema is checked in the browser and again on the server; a 422 from
  the FormRequest lands under the same fields, uncaught.
- Every form carries a hidden `homepage` field as a bot trap: filled in, the
  submission is answered as a success and dropped, and `handle` never runs.
  So no field may be named `homepage`. It is not a CAPTCHA - add
  `throttle` to a form worth attacking.

**Pages an editor builds** are `definePages` from `@rsc-kit/press/pages`, made
of `defineBlock` blocks (a schema and a server component each) and served by
one catch-all:

```
resources/js/app/[...path]/page.tsx       <site.Page params={params} />, generateMetadata = site.generateMetadata
resources/js/app/[...path]/middleware.ts  export default site.middleware
```

- `site.middleware` is required: without it a draft or a typo answers 200
  under the page's shell instead of 404.
- Add `site.resource` to `definePanel`'s resources. The source's rows are
  `{ id, title, slug, description?, published, blocks }` with `blocks` a JSON
  column; its `list` is asked for `filters: { slug }` and must honour it -
  filter the column in PHP.
- A looked-up page is kept with `cache()` for 60 seconds. A save in the panel
  calls `changed('press:pages')`; a write straight to the database shows on
  expiry, or at once after `Rsc::changed('press:pages')`.

**Uploads**: `fields: { photo: { type: 'image', storage, maxSize } }` on a
`z.url()` property (`type: 'file'` with `accept: '.pdf'` for documents), in a
resource, a block or a public form. `storage` is
`{ put(file, { field, tenant }) => Promise<string> }`; the string is what the
record saves. Press ships none: write to the bucket your Laravel disk reads,
since `rpc()` carries JSON and Laravel gets the url, not the file. Uploads are
stored only after the rest of the form passes.

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
- Never set `NODE_ENV` for the renderer. A built renderer
  started with no `NODE_ENV` sets the one it was built with in its own
  process before the app loads, so `process.env.NODE_ENV` and a typed
  `env.NODE_ENV` are `production` there. Laravel's `APP_ENV` is a separate
  setting.
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

## Pages a Cache May Keep

`export const revalidate = 60` in a page (or a layout; the smallest wins) lets a
shared cache keep the page for that many seconds. It is sent public only when
the render read nothing of the visitor; anything else sends it
`private, no-store`, with a `Cache-Status` header naming why and a log line
naming what read.

The renderer keeps a public one itself - ISR, with or without a CDN:

- A repeat request is answered from the kept copy, `X-RSC-Kit: cached`,
  without calling Laravel. Past `revalidate` the old copy goes out at once
  and one render replaces it behind the visitor.
- `Rsc::changed('posts')` makes the next request for any page that used a
  `posts`-tagged `cache()` answer render fresh, on every renderer instance,
  never stale. Laravel keeps the versions, so nothing more is installed for
  several pods.
- It is kept in `installCacheStore()`'s store (Redis) or 64 MB of the
  renderer's memory. It is off in dev and with `pageCache: false`.

- Its `rpc()` calls reach Laravel as nobody: no cookie, no `Authorization`,
  and a cookie Laravel sets is dropped. `auth()->user()` is null there.
- `cookies()`, `headers()`, `request()` or `connection()` anywhere in the
  render, Laravel route middleware declared in its `middleware.ts` (it runs
  against the visitor's session), or a cookie on the answer sends it private.
- A page that shows who is signed in is not one to keep. Leave `revalidate`
  off it.
- An `rpc()` wrapped in `cache(fn, { key, revalidate, tags })` puts its tags
  on the kept page as `Cache-Tag`, so a CDN that purges by tag can drop it
  when one changes. On Homeport (`HOMEPORT_PURGE_URL` set),
  `Rsc::changed()` posts that purge itself, after the transaction commits
  and the response has gone. Call it after the write, never before. A 429 is
  tried once more as a queued `RetryPurge` job, so that retry needs
  `queue:work` running; on a sync queue the PHP worker waits instead, up to
  five seconds, after the response. To see whether the edge answered, read
  its entry in `Cache-Status`: `X-RSC-Kit` is stored with the copy.
- A public page also sends `CDN-Cache-Control` with the same lifetimes, for a
  CDN that reads it. Homeport's edge caches on nothing else, and strips it
  before the answer leaves, so it is not missing when a browser does not show
  it there.

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
- A release step is `php artisan migrate` and a queue worker is `php artisan
  queue:work`: Laravel's, run where Laravel runs. A file in `src/commands` is
  for work the renderer itself needs, run as `node .output/server/commands.mjs
  <name>` (`./app <name>` in a compiled binary). It returns its exit code, and
  it does not import `instrumentation.ts` unless it exports `register = true`:
  only then are that file's environment checks and `register()` run for it.
  Never `node .output/server/index.mjs <name>`: that is the server, and it
  starts.

## Do Not

- Import `rpc`, or call it from a client component.
- Write `route.php`, a socket, `rsc:serve`, or anything that renders in PHP.
- Edit `server-actions.generated.ts` or `rsc-host.json` by hand.
- Write a `src/commands` file to migrate the database or drain Laravel's
  queue; those are `php artisan`.
- Add `loading.tsx` to fix a refused route; move the read into a slot.
- Check access in a layout; use `middleware.ts`.
- Poll with `usePolling` for data the backend can announce: `refreshOn` plus
  `Rsc::changed()`.
- Put `shared: true` on a section that shows anything per visitor.
- Reuse `RSC_HOST_CALL_SECRET` as `RSC_SIGNING_SECRET`; they are two keys
  for two jobs.
- Use the old `laravel-rsc/form` or `rscRoutes` imports; it is
  `@rsc-kit/core/form` and `rscKit` from `@rsc-kit/core/vite`.
