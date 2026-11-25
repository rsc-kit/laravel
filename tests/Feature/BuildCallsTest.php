<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use RscKit\Build\BuildCalls;
use RscKit\CallableRegistry;
use RscKit\Console\RscHostManifestCommand;
use RscKit\Rsc;
use RscKit\RscRedirectException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Fixtures\BuildForm;
use Tests\Fixtures\BuildGuarded;
use Tests\Fixtures\BuildGuardedChild;
use Tests\Fixtures\BuildTakesRequest;
use Tests\Fixtures\BuildTakesSession;
use Tests\Fixtures\BuildTakesUser;
use Tests\Fixtures\BuildTakesUserByAttribute;
use Tests\Fixtures\BuildTeam;

require_once __DIR__.'/../fixtures/BuildFunctions.php';

/**
 * rpc() during a build: what is the same for everyone is answered, and what
 * reaches for a visitor says so instead of answering for a guest.
 */
function ask(mixed $callable, array $args = []): array
{
    app(CallableRegistry::class)->register('Test.fn', $callable);

    $answer = app(BuildCalls::class)->answer(json_encode(['id' => 1, 'function' => 'Test.fn', 'args' => $args]));

    return json_decode($answer['json'], true) + ['ended' => $answer['last']];
}

beforeEach(fn () => config(['app.url' => 'http://localhost']));

describe('answered', function () {
    it('answers what is the same for everyone', function (mixed $callable, mixed $expected, array $args = []) {
        expect(ask($callable, $args))->toBe(['id' => 1, 'status' => 200, 'result' => $expected, 'ended' => false]);
    })->with([
        'a value' => [fn () => ['min' => 8], ['min' => 8]],
        'arguments' => [fn (int $a, int $b) => $a + $b, 5, [2, 3]],
        'config' => [fn () => config('app.name'), 'Laravel'],
        'a translation' => [fn () => __('auth.failed'), 'These credentials do not match our records.'],
        'a url' => [fn () => url('/orders'), 'http://localhost/orders'],
        'an asset' => [fn () => asset('a.png'), 'http://localhost/a.png'],
        'the request, its url' => [fn () => request()->path(), '__rsc/host-call'],
        'the request, the host' => [fn () => request()->getSchemeAndHttpHost(), 'http://localhost'],
        'the request, its input' => [fn () => request()->input('page', 1), 1],
        'a page number' => [fn () => Paginator::resolveCurrentPage(), 1],
        'nothing' => [fn () => null, null],
    ]);

    it('answers a named route', function () {
        Route::get('/orders/{order}', fn () => '')->name('orders.show');
        Route::getRoutes()->refreshNameLookups();

        expect(ask(fn () => route('orders.show', 7))['result'])->toBe('http://localhost/orders/7');
    });

    it('keeps the outcomes apart, as the endpoint does', function () {
        expect(ask(fn () => abort(404)))->toMatchArray(['status' => 404, 'refusalStatus' => 404]);
        expect(ask(fn () => Rsc::refuse('No.', ['a' => 1])))->toMatchArray(['status' => 409, 'refusalData' => ['a' => 1]]);
        expect(ask(fn () => throw new RscRedirectException('/new')))->toMatchArray(['status' => 200, 'redirect' => '/new']);
        expect(ask(fn () => throw ValidationException::withMessages(['name' => 'No.'])))->toMatchArray(['status' => 422, 'validationErrors' => ['name' => ['No.']]]);
    });

    it('says what failed and where, whatever app.debug is, and reports nothing', function () {
        config(['app.debug' => false]);
        Log::shouldReceive('error')->never();

        $answer = ask(fn () => throw new RuntimeException('no such table: plans'));

        expect($answer)->toMatchArray(['status' => 500, 'error' => 'no such table: plans']);
        expect($answer['debug']['trace'][0])->toContain('BuildCallsTest.php:');
    });

    it('does not carry a locale to the next call', function () {
        ask(function (string $locale) {
            app()->setLocale($locale);
        }, ['fr']);

        expect(app()->getLocale())->toBe('en');
    });
});

describe('needs a visitor', function () {
    it('notices a read', function (Closure $read, string $what) {
        expect(ask($read))->toBe(['id' => 1, 'visitor' => [$what], 'last' => true, 'ended' => true]);
    })->with([
        'session()' => [fn () => session('cart'), 'the session'],
        'session()->get' => [fn () => session()->get('cart'), 'the session'],
        'session()->has' => [fn () => session()->has('cart'), 'the session'],
        'session()->all' => [fn () => session()->all(), 'the session'],
        'session()->getId' => [fn () => session()->getId(), 'the session'],
        'session()->put' => [fn () => session()->put('a', 1), 'the session'],
        'session()->flash' => [fn () => session()->flash('a', 1), 'the session'],
        'session()->token' => [fn () => session()->token(), 'the session'],
        'session()->isStarted' => [fn () => session()->isStarted(), 'the session'],
        'session()->flush' => [fn () => session()->flush(), 'the session'],
        'csrf_token()' => [fn () => csrf_token(), 'the session'],
        'old()' => [fn () => old('name'), 'the session'],
        'Session::get' => [fn () => Session::get('cart'), 'the session'],
        'a driver by name' => [fn () => Session::driver('file')->get('cart'), 'the session'],
        'request()->session()' => [fn () => request()->session()->get('cart'), 'the session'],
        'redirect()->intended' => [fn () => redirect()->intended('/')->getTargetUrl(), 'the session'],
        'Cookie::get' => [fn () => Cookie::get('theme'), 'a cookie'],
        'request()->cookie' => [fn () => request()->cookie('theme'), 'a cookie'],
        'request()->cookies' => [fn () => request()->cookies->get('theme'), 'a cookie'],
        'request()->cookies->all' => [fn () => request()->cookies->all(), 'a cookie'],
        'request()->hasCookie' => [fn () => request()->hasCookie('theme'), 'a cookie'],
        'a queued cookie' => [fn () => Cookie::queue('theme', 'dark'), 'a cookie'],
        'header()' => [fn () => request()->header('Accept-Language'), 'a request header'],
        'headers' => [fn () => request()->headers->get('X-Team'), 'a request header'],
        'headers->has' => [fn () => request()->headers->has('X-Team'), 'a request header'],
        'headers, looped' => [fn () => iterator_to_array(request()->headers), 'a request header'],
        'bearerToken' => [fn () => request()->bearerToken(), 'a request header'],
        'userAgent' => [fn () => request()->userAgent(), 'a request header'],
        'getPreferredLanguage' => [fn () => request()->getPreferredLanguage(['en', 'fr']), 'a request header'],
        'Request::header' => [fn () => RequestFacade::header('X-Team'), 'a request header'],
        'ip' => [fn () => request()->ip(), "the visitor's address"],
        'ips' => [fn () => request()->ips(), "the visitor's address"],
        'getClientIp' => [fn () => request()->getClientIp(), "the visitor's address"],
        'server' => [fn () => request()->server('HTTP_USER_AGENT'), 'the request'],
        'server bag' => [fn () => request()->server->get('REMOTE_ADDR'), 'the request'],
        'attributes' => [fn () => request()->attributes->get('team'), 'a request attribute'],
        'a limiter keyed on the address' => [fn () => RateLimiter::tooManyAttempts('x:'.request()->ip(), 5), "the visitor's address"],
        'a refusal to anyone signed out' => [fn () => throw new AuthenticationException, 'who is signed in'],
        'a refusal to anyone' => [fn () => throw new AuthorizationException, 'what they may do'],
        'abort(403)' => [fn () => abort(403), 'what they may do'],
    ]);

    it('notices the user, however they are asked for', function (Closure $read) {
        expect(ask($read)['visitor'])->toContain('the session');
    })->with([
        'auth()->user()' => [fn () => auth()->user()],
        'auth()->id()' => [fn () => auth()->id()],
        'auth()->check()' => [fn () => auth()->check()],
        'auth()->guest()' => [fn () => auth()->guest()],
        'Auth::guard' => [fn () => Auth::guard('web')->user()],
        'request()->user()' => [fn () => request()->user()],
        'a gate' => [fn () => Gate::allows('anything')],
        'an error on a missing user' => [fn () => auth()->user()->orders()],
        'a caught error on one' => [fn () => rescue(fn () => auth()->user()->orders(), [], false)],
        'the previous url' => [fn () => URL::previous()],
        'Rsc::flash' => [function () {
            Rsc::flash('Saved.');
            session('x');
        }],
    ]);

    it('notices a token guard, which reads a header', function () {
        Auth::viaRequest('test-token', fn (Request $request) => $request->bearerToken() === 'x' ? new stdClass : null);
        config(['auth.guards.api' => ['driver' => 'test-token']]);

        expect(ask(fn () => Auth::guard('api')->check())['visitor'])->toBe(['a request header']);
    });

    it('notices one that arrived through a constructor', function (string $class, string $method, string $what) {
        expect(ask([$class, $method])['visitor'])->toContain($what);
    })->with([
        [BuildTakesRequest::class, 'agent', 'a request header'],
        [BuildTakesSession::class, 'cart', 'the session'],
        [BuildTakesUser::class, 'name', 'the session'],
        [BuildTakesUserByAttribute::class, 'name', 'the session'],
        [BuildTakesUserByAttribute::class, 'maybe', 'the session'],
    ]);

    it('does not run what is guarded, or takes a form', function (mixed $callable, string $what) {
        expect(ask($callable))->toBe(['id' => 1, 'visitor' => [$what], 'ended' => false]);
    })->with([
        [[BuildGuarded::class, 'signedIn'], '#[Authenticated]'],
        [[BuildGuarded::class, 'allowed'], '#[Can]'],
        [[BuildGuarded::class, 'throttled'], '#[Middleware]'],
        [[BuildGuardedChild::class, 'inherited'], '#[Authenticated]'],
        [fn (BuildForm $form) => throw new LogicException('ran'), 'a form'],
    ]);

    it('ends with a call that read one, so nothing kept from it answers the next', function () {
        app()->singleton(BuildTeam::class);

        $first = ask(fn () => app(BuildTeam::class)->current());

        expect($first['visitor'])->toBe(['the session'])
            ->and($first['ended'])->toBeTrue()
            ->and($first['last'])->toBeTrue();
    });

    it('does not carry a read to the next call', function () {
        ask(fn () => session('cart'));

        expect(ask(fn () => 1))->toMatchArray(['status' => 200, 'result' => 1]);
    });

    it('notices a read on every call, not only the first', function () {
        $read = fn () => request()->cookies->get('theme') ?? request()->header('X') ?? null;

        expect(ask($read)['visitor'])->toBe(['a cookie', 'a request header'])
            ->and(ask($read)['visitor'])->toBe(['a cookie', 'a request header']);
    });
});

describe('the command', function () {
    function runCalls(string $lines): string
    {
        $in = fopen('php://memory', 'r+');
        fwrite($in, $lines);
        rewind($in);

        $command = app(RscHostManifestCommand::class);
        $command->setLaravel(app());

        $input = new ArrayInput(['--calls' => true]);
        $input->setStream($in);
        $output = new BufferedOutput;
        $command->run($input, $output);

        return $output->fetch();
    }

    it('answers each line in turn, and ends after a call that read the visitor', function () {
        $registry = app(CallableRegistry::class);
        $registry->register('A', fn () => 'a');
        $registry->register('B', fn () => session('x'));
        $registry->register('C', fn () => 'c');

        $lines = runCalls('{"id":1,"function":"A","args":[]}'."\n\nnot json\n".'{"id":2,"function":"B","args":[]}'."\n".'{"id":3,"function":"C","args":[]}'."\n");

        expect(array_map(fn ($line) => json_decode($line, true), array_values(array_filter(explode("\n", $lines)))))->toBe([
            ['id' => 1, 'status' => 200, 'result' => 'a'],
            ['id' => null, 'status' => 400, 'error' => 'A build call needs an "id".'],
            ['id' => 2, 'visitor' => ['the session'], 'last' => true],
        ]);
    });

    it('keeps what a function prints out of the answers', function () {
        app(CallableRegistry::class)->register('Loud', function () {
            echo '{"id":1,"status":200,"result":"not this"}';

            return 'this';
        });

        expect(trim(runCalls('{"id":1,"function":"Loud","args":[]}'."\n")))->toBe('{"id":1,"status":200,"result":"this"}');
    });

    it('answers an unknown name, and encodes what it can', function () {
        app(CallableRegistry::class)->register('Inf', fn () => INF);

        [$unknown, $inf] = array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", runCalls('{"id":"x","function":"Nope","args":[]}'."\n".'{"id":"y","function":"Inf","args":[]}'."\n")))));

        expect($unknown)->toMatchArray(['id' => 'x', 'status' => 404])
            ->and($inf)->toMatchArray(['id' => 'y', 'status' => 500]);
    });

    it('is named in the manifest', function () {
        $this->artisan('rsc:host-manifest', ['--print' => true])->expectsOutputToContain('"--calls"');
    });
});
