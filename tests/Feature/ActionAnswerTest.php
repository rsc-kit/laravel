<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Pipeline;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;

/**
 * What an action returns, when it returns what a controller would.
 *
 * redirect(), to_route() and back() are Laravel's way to end a POST, and
 * Fortify's actions answer with a Responsable that decides between them. Each
 * is performed as a guard's redirect is; before, it was serialised as an
 * object of headers and the visitor stayed where they were.
 */
class SignsIn implements Responsable
{
    public function toResponse($request)
    {
        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended('/dashboard');
    }
}

beforeEach(function () {
    config()->set('rsc.host_call_secret', 'answer-secret');
    Route::post('/__rsc/host-call', HostCallController::class)->middleware('web');
    Route::get('/settings/profile', fn () => null)->name('profile.edit');
    Route::get('/login', fn () => null)->name('login');
    app('router')->getRoutes()->refreshNameLookups();

    app()->forgetInstance(HostCallDispatcher::class);
    $registry = app(CallableRegistry::class);
    $registry->register('Answer.redirect', fn () => redirect('/dashboard'));
    $registry->register('Answer.route', fn () => to_route('profile.edit')->with('status', 'saved'));
    $registry->register('Answer.back', fn () => back());
    $registry->register('Answer.responsable', fn () => new SignsIn);
    $registry->register('Answer.json', fn () => response()->json(['ok' => true]));
    // Fortify's login, in small: a routing pipeline renders what its pipes
    // throw, so the controller returns the exception as a response.
    $registry->register('Answer.rendered', fn () => (new Pipeline(app()))->send(request())
        ->through([fn () => throw ValidationException::withMessages(['email' => 'These credentials do not match our records.'])])
        ->then(fn () => new SignsIn));
    $registry->register('Answer.renderedAuth', fn () => (new Pipeline(app()))->send(request())
        ->through([fn () => throw new AuthenticationException])
        ->then(fn () => new SignsIn));
    $registry->register('Answer.thrown', fn () => throw new HttpResponseException(redirect('/dashboard')));
    $registry->register('Answer.withErrors', fn () => back()->withInput(['email' => 'who@example.com'])->withErrors(['email' => "We can't find a user with that email address."]));
    $registry->register('Answer.view', fn () => response('<p>a page</p>'));
});

/** @param  array{url: string, params?: array<string, mixed>}|null  $page */
function answerFrom(string $function, ?array $page = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_RSC_HOST_SECRET' => 'answer-secret'];

    if ($page !== null) {
        $server['HTTP_X_RSC_PAGE'] = json_encode($page);
    }

    return test()->call('POST', '/__rsc/host-call', [], [], [], $server, json_encode(['function' => $function, 'args' => []]));
}

it('performs a redirect it returns', function () {
    answerFrom('Answer.redirect')->assertOk()->assertExactJson(['redirect' => '/dashboard', 'redirectStatus' => 302]);
});

it('performs to_route(), and keeps what it flashed', function () {
    answerFrom('Answer.route')->assertOk()->assertJson(['redirect' => '/settings/profile']);

    expect(session('status'))->toBe('saved');
});

it('sends back() to the page the form was posted from', function () {
    answerFrom('Answer.back', ['url' => 'http://localhost/settings/security', 'params' => []])
        ->assertOk()
        ->assertJson(['redirect' => '/settings/security']);
});

it('asks a Responsable what it is, as the visitor\'s browser would be answered', function () {
    session()->put('url.intended', 'http://localhost/settings/profile');

    answerFrom('Answer.responsable')->assertOk()->assertJson(['redirect' => '/settings/profile']);
});

it('answers with the data of a JsonResponse', function () {
    answerFrom('Answer.json')->assertOk()->assertExactJson(['result' => ['ok' => true]]);
});

it('fails loudly on a response that is neither', function () {
    Exceptions::fake();

    answerFrom('Answer.view')->assertServerError();

    Exceptions::assertReported(fn (LogicException $e) => str_contains($e->getMessage(), '[Answer.view] answered with a Illuminate\Http\Response'));
});

it('answers an exception rendered into a response as the exception it was', function () {
    answerFrom('Answer.rendered', ['url' => 'http://localhost/login', 'params' => []])
        ->assertStatus(422)
        ->assertJsonPath('validationErrors.email.0', 'These credentials do not match our records.');

    answerFrom('Answer.renderedAuth')->assertStatus(401)->assertJson(['unauthenticated' => true]);
});

it('answers an HttpResponseException with its response', function () {
    answerFrom('Answer.thrown')->assertOk()->assertJson(['redirect' => '/dashboard']);
});

it('answers back()->withErrors() as the invalid form it says it was', function () {
    answerFrom('Answer.withErrors', ['url' => 'http://localhost/forgot-password', 'params' => []])
        ->assertStatus(422)
        ->assertJsonPath('validationErrors.email.0', "We can't find a user with that email address.");

    expect(session()->has('errors'))->toBeFalse()
        ->and(session()->has('_old_input'))->toBeFalse();
});
