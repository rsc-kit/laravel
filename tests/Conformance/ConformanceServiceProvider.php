<?php

namespace Tests\Conformance;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use RscKit\CallableRegistry;
use RscKit\Rsc;
use RscKit\RscRedirectException;
use RuntimeException;

/**
 * The Conformance.* functions rsc-kit's suite calls, written the way an app
 * would write them on this package: so the suite checks what an app built on
 * it would send.
 *
 *     vendor/bin/testbench serve --port=8125
 *     npx -y -p @rsc-kit/core rsc-kit-conformance --endpoint http://127.0.0.1:8125/__rsc/host-call --secret test
 */
class ConformanceServiceProvider extends ServiceProvider
{
    public function boot(Router $router, CallableRegistry $registry): void
    {
        $router->aliasMiddleware('conformance-allow', fn (Request $request, Closure $next) => $next($request));
        $router->aliasMiddleware('conformance-deny', fn () => abort(403, 'Not for you.'));
        $router->aliasMiddleware('conformance-redirect', fn () => redirect('/conformance-login'));

        $registry->register('Conformance.echo', fn (mixed $value): mixed => $value);
        $registry->register('Conformance.emptyList', fn (): array => []);
        $registry->register('Conformance.time', fn (): Carbon => Carbon::parse('2026-01-02T03:04:05Z'));
        $registry->register('Conformance.noTime', fn (): ?Carbon => null);
        $registry->register('Conformance.unauthenticated', fn () => throw new AuthenticationException);
        $registry->register('Conformance.unauthorized', fn () => throw new AuthorizationException);
        $registry->register('Conformance.notFound', fn () => abort(404));
        $registry->register('Conformance.refuse', fn () => abort(429, 'Slow down.'));
        $registry->register('Conformance.refuseWithData', fn () => Rsc::refuse('Still in use', [
            'blockers' => [['id' => 7, 'href' => '/orders/7']],
        ]));
        $registry->register('Conformance.invalid', fn () => throw ValidationException::withMessages(['name' => 'The name field is required.']));
        $registry->register('Conformance.redirect', fn () => throw new RscRedirectException('/login'));
        $registry->register('Conformance.revalidate', function (): string {
            Rsc::revalidate('orders');

            return 'ok';
        });
        $registry->register('Conformance.fail', fn () => throw new RuntimeException('boom'));
        $registry->register('Conformance.change', function (): string {
            Rsc::changed('conformance:changed');

            return 'ok';
        });
        $registry->register('Conformance.authorization', fn (): ?string => request()->header('Authorization'));
        $registry->register('Conformance.cookie', fn (): ?string => request()->header('Cookie'));
        $registry->register('Conformance.login', function (): string {
            Cookie::queue('conformance_login', '1');

            return 'ok';
        });
        $registry->register('Conformance.double', fn (int $n): int => $n * 2);
        $registry->register('Conformance.invalidNested', fn () => throw ValidationException::withMessages([
            'address.city' => 'The city is required.',
            '' => 'The address could not be checked.',
        ]));
    }
}
