# rsc-kit for Laravel

## Overview

React Server Components with Laravel behind them. The renderer — a JS process
running `@rsc-kit/core/host` — owns the request: routing, rendering,
prerendering and static serving. Laravel answers what only it can: the data,
the session, and whether a route may render.

The package is `rsc-kit/laravel` (namespace `RscKit\`). It is about 400 lines,
and there used to be 7,300: everything the renderer now owns lived here, in two
implementations that could and did disagree.

## Architecture

- `src/` — the service provider, the host-call endpoint, the callable registry, the middleware runner
- `tests/` — Pest (Unit + Feature)

The JavaScript half is a separate package, `@rsc-kit/core`, in its own
repository (`~/Herd/rsc-kit`). Nothing here loads it: the two processes speak
HTTP, so PHP no longer locates, launches or supervises a runtime.

## Key Conventions

- PHP follows Laravel conventions with Pint formatting
- Client components use `"use client"`, server actions `"use server"`
- Route middleware is declared in a colocated `route.ts`, in Laravel's own vocabulary
- Always run `vendor/bin/pint --dirty --format agent` after modifying PHP files

## Testing

`vendor/bin/pest --compact` from the package root.

What it covers is what is left: reflection-based discovery, the host-call
endpoint's contract, and the middleware runner. Rendering is the engine's, and
its tests live in `~/Herd/rsc-kit` — a rendering regression cannot be caught
from here, because nothing here renders.

## Development Setup

- Package source: `/Users/ramonmalcolm/Herd/lara-bun`
- Integration app: `/Users/ramonmalcolm/Herd/larabun-docs` — run `php artisan serve` and `bun server.ts` side by side; both read `RSC_HOST_CALL_SECRET`
- After package changes: `composer update rsc-kit/laravel` in consuming apps
- After TS changes: rebuild with Vite, then restart the renderer

## Critical Patterns

These are not API surface. They are the things that fail silently.

### Discovery Is the Host's, Placement Is the Build's

Server actions are found by PHP and written by the plugin, and the split is
deliberate. Discovery is reflection over the app's own classes — `class_exists`
through Composer's autoloader, `getMethods(IS_PUBLIC)` returning what a class
inherits from parents and traits — none of which a JS reimplementation could do
except by regex, which would silently miss every inherited action. So PHP
discovers and hands the map over as `RSC_HOST_ACTIONS`; `writeHostBindings()`
renders the `"use server"` stubs, `rsc-env.d.ts` and `rsc-types.d.ts` into
`sourceDir`, because the app imports them by relative path and only the build
knows that path.

Rewritten every run, all three: a stale stub calls a global that has since been
renamed and nothing fails until the browser. That is also why the global's name
travels as `RSC_HOST_GLOBAL` rather than being written down twice.

### A Refusal Must Never Look Like Silence

Two places decide whether a page renders, and both fail closed on purpose.

`RouteMiddleware::run()` returns true only when Laravel's pipeline reached the
end. Everything else throws, and the engine treats anything that is not a
literal `true` as a refusal — so a middleware that aborts, redirects or simply
errors keeps the page from rendering rather than being read as silence.

`HostCallDispatcher` keeps the outcomes apart rather than collapsing them. A
refusal is 422 with its fields in `validationErrors`; unauthenticated is 401,
unauthorized 403; a middleware that aborted keeps its own status. A redirect is
answered **200** with the destination in the body, never as a 3xx — an HTTP
client follows one transparently, so a real redirect would send the host call
itself to the destination and hand back whatever it found as the result.

`hash_equals('', '')` is TRUE, which is why an unconfigured secret is checked
before the comparison rather than trusted to it.

### CSRF Is Deliberately Not on the Endpoint

The endpoint carries the session middleware — the renderer forwards the
visitor's cookie, `EncryptCookies` decrypts it, `StartSession` binds their
session, and a function reading `auth()->user()` finds the person the page is
being rendered for.

It does not carry `VerifyCsrfToken`, and with it every call answers 419. CSRF
protects a browser from being tricked into posting with the user's cookies; the
caller here holds a shared secret, which a browser cannot be tricked into
sending. The alternative was asking every application to add an exception in
`bootstrap/app.php`.

`AddQueuedCookiesToResponse` is what lets a call log someone in: a cookie queued
during it reaches this response, and the renderer puts it on the page's.

### A Failed Render Is Not a Page to Freeze

React reports a failed row *inside* the payload rather than by rejecting — a
rejection inside a Suspense boundary never reaches the caller — so a render
"succeeds" and the result looks storable. It is not: the browser decodes that
row, throws, unmounts the document, and shows a blank page with the error
nowhere near its cause. Worse, the file outlives the build.

This cost three pages in the docs app, blank on every visit until someone
rebuilt, with nothing in the console.

### What Moved to the Engine

Routing, rendering, prerendering, static serving, the header protocol, redirect
delivery and partial-navigation depth all live in `@rsc-kit/core` now. There
used to be a second implementation of each here, and they drifted twice — an
interception that answered with a whole segment instead of the slot, and a PPR
shell probe that dropped its page key. Both looked like working code.

Engine behaviour is documented in `~/Herd/rsc-kit/CLAUDE.md`. Do not
reintroduce a copy of it here.
