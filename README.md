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
- **PHP callables** — reach Eloquent, auth and sessions from a server component with `rpc()`
- **Server actions** — `"use server"` functions for mutations, with your validation
- **Your middleware, per route** — `auth`, `verified`, `throttle:60,1`, `can:update,post`, run through Laravel's own pipeline
- **Streaming HTML** — Suspense boundaries fill in progressively
- **Partial prerendering** — a static shell at build time, the rest streamed
- **Parallel routes and interception** — `@folder` slots, `(.)` modals
- **Typed routes** — the build writes the urls it found, so a link to a page that does not exist fails the typecheck

## How it fits together

Two processes. The renderer serves the browser; Laravel answers it.

```
browser  →  renderer  ──render──▶  React
                │
                ├─ before rendering:  POST /__rsc/host-call  {"middleware":["auth"]}
                └─ during rendering:  POST /__rsc/host-call  {"function":"Orders.recent","args":[5]}
                                              │
                                              ▼
                                        your Laravel app
```

Both are the same endpoint, and it is off until you give it a secret. It runs
registered functions by name with none of your routing in front of it, so keep
it unreachable from outside as well as authenticated — bind the renderer to
loopback, or serve the endpoint on a unix socket, which HTTP runs over
unchanged and which opens no port at all.

## Quick start

```sh
composer require rsc-kit/laravel
bun add @rsc-kit/core react react-dom
```

```env
RSC_HOST_CALL_SECRET=a-long-random-string
```

A function your components can call:

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
// resources/js/rsc/app/page.tsx
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
// resources/js/rsc/app/admin/route.ts
export const middleware = ['auth', 'can:update,post'];
```

Those are ordinary Laravel middleware. The renderer asks before anything at or
below that route renders, and a refusal is the answer to the request.

## Requirements

- PHP 8.2+
- Laravel 11+
- [Bun](https://bun.sh) or Node 24+, for the renderer
- React 19

## Documentation

Guides, live demos and the full API at **[rsc-kit.dev](https://rsc-kit.dev)**.

## Support

Issues and discussion at [rsc-kit/laravel](https://github.com/rsc-kit/laravel).
