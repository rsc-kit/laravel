---
name: pest-testing
description: "Tests the rsc-kit/laravel package with Pest 4 on Testbench. Activates when writing or debugging tests in this repo, asserting on a host-call reply, a refusal, route middleware, action discovery or an artisan command, or running the conformance suite."
license: MIT
metadata:
  author: rsc-kit
---

# Pest Testing for rsc-kit/laravel

## When to Apply

- Creating or changing a test under `tests/`
- Debugging a failing test, or the conformance suite in CI
- Changing what the host-call endpoint answers, which also means the conformance suite

## What Is Tested Here, and What Is Not

This package answers what only Laravel can: the data, the session, and whether
a route may render. Rendering belongs to `@rsc-kit/core` in `~/Herd/rsc-kit`,
and **a rendering regression cannot be caught from here** because nothing here
renders. Do not write a test that needs a renderer; put it in the engine.

## Structure

- `tests/Unit/` - pure classes: `CallableRegistry`, the attributes, `RscRedirectException`, `RendererRoutes`. There is no booted app; `app()` is a bare container, so anything that needs config, the router or the service provider goes in Feature.
- `tests/Feature/` - the Testbench app with `RscKitServiceProvider` (`tests/Pest.php` applies `Tests\TestCase` to this directory only): the host-call endpoint, route middleware, action discovery, the commands, the proxy.
- `tests/Conformance/ConformanceServiceProvider.php` - the `Conformance.*` functions rsc-kit's suite calls, written the way an app would write them.
- `tests/fixtures/` - a route manifest, a renderer stub, a minimal `rsc-app`.

## Running Tests

```bash
vendor/bin/pest --compact                              # all
vendor/bin/pest --compact tests/Feature/RouteMiddlewareTest.php
vendor/bin/pest --compact --filter="Rsc::refuse"       # by name
```

The conformance suite is the engine's, run against this package the way CI does:

```bash
(cd ~/Herd/rsc-kit/packages/core && bun run build)
vendor/bin/testbench rsc:host-manifest --print > /tmp/rsc-host.json
PHP_CLI_SERVER_WORKERS=4 vendor/bin/testbench serve --port=8125 --no-reload &
node ~/Herd/rsc-kit/packages/core/dist/conformance.js \
  --endpoint http://127.0.0.1:8125/__rsc/host-call --secret test --manifest /tmp/rsc-host.json
```

Four workers and `--no-reload` are not optional: the suite's batch cases need a
server that can answer a second request while the first is open, and without
the flag Laravel starts one worker anyway.

## Key Patterns

### Dispatch, don't boot a controller

`HostCallEndpointTest` builds a dispatcher around a registry and asserts on
what goes on the wire. The controller decides nothing, so this is the contract:

```php
function dispatcherWith(array $callables, string $secret = 's3cret'): HostCallDispatcher
{
    $registry = new CallableRegistry(app());

    foreach ($callables as $name => $callable) {
        $registry->register($name, $callable);
    }

    return new HostCallDispatcher($registry, app(Revalidation::class), $secret);
}

$answer = dispatcherWith(['Orders.recent' => fn (int $n) => range(1, $n)])
    ->dispatch(['function' => 'Orders.recent', 'args' => [3]]);

expect($answer['status'])->toBe(200)->and($answer['reply'])->toBe(['result' => [1, 2, 3]]);
```

`dispatch()` returns `['status' => int, 'reply' => array]`. A batch is `['calls' => [...]]`.

### A refusal is an answer, a failure is reported

Assert the reply a refusal sends and that nothing was reported; assert the
opposite for a failure. `Exceptions::fake()` is how:

```php
Exceptions::fake();

$answer = dispatcherWith([
    'Projects.delete' => fn () => Rsc::refuse('Still in use', ['blockers' => $links]),
])->dispatch(['function' => 'Projects.delete', 'args' => []]);

expect($answer['status'])->toBe(409)
    ->and($answer['reply'])->toBe([
        'error' => 'Still in use',
        'refusalStatus' => 409,
        'refusalData' => ['blockers' => $links],
    ]);

Exceptions::assertNothingReported();   // a failure: Exceptions::assertReported(RuntimeException::class)
```

The statuses the engine reads: validation 422 with `validationErrors`,
`unauthenticated` 401, `unauthorized` 403, a redirect **200** with `redirect`,
an `abort()` its own status with `refusalStatus`. A 5xx from `abort()` is
reported like a failure. `refusalStatus` is what makes `error` a message the
visitor sees, so an `abort()` under test should carry a message; without one
the reply says `Refused.`.

### Route middleware fails closed

`RouteMiddleware::run()` returns true only when the pipeline reached its end.
Test that anything else throws, never that it returns false:

```php
app('router')->aliasMiddleware('waves', fn (Request $request, Closure $next) => $next($request));
app('router')->aliasMiddleware('denies', fn () => abort(403, 'Not for you.'));

expect(runner()->run(['waves']))->toBeTrue();
expect(fn () => runner()->run(['denies']))->toThrow(HttpException::class);
```

### Commands write files

`make:rsc-action` tests clean `app_path('Rsc')` before and after, run the
command with `$this->artisan(...)->assertSuccessful()`, and assert on the
written source and on `rsc-host.json`.

### Pages registered ahead of the app's

`RendererPagesTest` decides the route table before the package boots, so it
sets `RSC_TEST_ROUTES_MANIFEST` with `putenv()` at load and unsets it after;
`TestCase::defineEnvironment()` reads it. Do the same for a test that needs a
different table.

## Critical Tests

- `HostCallEndpointTest` - the wire contract: refusals are not failures, a redirect is a 200, every call in a batch is answered, the secret is checked before anything else.
- `RouteMiddlewareTest` - a middleware that aborts, redirects or errors keeps the page from rendering.
- `RendererPagesTest` - the React tree wins `/` over a fresh app's welcome route.
- `CallableRegistryAuthorizationTest` and `ActionDiscoveryTest` - an inherited action is found, and a guard on a method is the guard that runs.

## When the Wire Changes

A change to a reply shape, a status or what counts as a refusal is a change to
the contract with the engine. Add the case to `ConformanceServiceProvider` and
to `packages/core/src/conformance.ts` in `~/Herd/rsc-kit` in the same pass, run
the suite above, and update `PROTOCOL.md`. CI runs the engine's suite from its
`main`, so an engine-side change reaches this repo's CI without a commit here.

## After Writing Tests

Run `vendor/bin/pint --dirty --format agent` from the package root.
