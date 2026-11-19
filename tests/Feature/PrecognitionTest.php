<?php

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;

/**
 * A form's fields checked before it is submitted - Laravel's Precognition,
 * asked for by the renderer with `validate` on a host call. The form
 * request's authorize() and rules run, filtered to the fields named; the
 * method never does.
 */
beforeEach(function () {
    config()->set('rsc.host_call_secret', 'check-secret');
    Route::post('/__rsc/host-call', HostCallController::class)->middleware('web');

    app()->forgetInstance(HostCallDispatcher::class);
    PrecognitiveSubscribe::$ran = 0;
    app(CallableRegistry::class)->register('Newsletter.subscribe', [PrecognitiveSubscribe::class, 'store']);
});

function checkHost(array $body): TestResponse
{
    return test()->call('POST', '/__rsc/host-call', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.str_replace('-', '_', strtoupper(HostCallDispatcher::SECRET_HEADER)) => 'check-secret',
    ], json_encode($body));
}

it('answers the named field\'s errors and never runs the method', function () {
    checkHost(['function' => 'Newsletter.subscribe', 'args' => [['email' => 'nope', 'name' => '']], 'validate' => ['email']])
        ->assertStatus(422)
        ->assertJsonPath('validationErrors.email.0', 'The email field must be a valid email address.')
        ->assertJsonMissingPath('validationErrors.name');

    expect(PrecognitiveSubscribe::$ran)->toBe(0);
});

it('answers a null result when the fields pass, whatever the others hold', function () {
    checkHost(['function' => 'Newsletter.subscribe', 'args' => [['email' => 'a@b.co', 'name' => '']], 'validate' => ['email']])
        ->assertOk()
        ->assertExactJson(['result' => null]);

    expect(PrecognitiveSubscribe::$ran)->toBe(0);
});

it('checks every rule when no field is named', function () {
    checkHost(['function' => 'Newsletter.subscribe', 'args' => [['email' => 'a@b.co', 'name' => '']], 'validate' => []])
        ->assertStatus(422)
        ->assertJsonPath('validationErrors.name.0', 'The name field is required.');

    expect(PrecognitiveSubscribe::$ran)->toBe(0);
});

it('matches a nested field by wildcard, as Laravel does', function () {
    checkHost(['function' => 'Newsletter.subscribe', 'args' => [['email' => 'a@b.co', 'name' => 'Ada', 'tags' => ['ok', '']]], 'validate' => ['tags.*']])
        ->assertStatus(422)
        ->assertJsonPath('validationErrors', ['tags.1' => ['The tags.1 field is required.']]);
});

it('tells rules() the request is precognitive', function () {
    checkHost(['function' => 'Newsletter.subscribe', 'args' => [['email' => 'a@b.co', 'name' => 'Ada']], 'validate' => ['email']])
        ->assertOk();

    expect(PrecognitiveSubscribeRequest::$sawPrecognitive)->toBeTrue();
});

it('still refuses one the form request does not authorize', function () {
    checkHost(['function' => 'Newsletter.subscribe', 'args' => [['email' => 'a@b.co', 'name' => 'blocked']], 'validate' => ['email']])
        ->assertStatus(403);
});

it('runs a submit as it always did', function () {
    checkHost(['function' => 'Newsletter.subscribe', 'args' => [['email' => 'a@b.co', 'name' => 'Ada']]])
        ->assertOk()
        ->assertJsonPath('result.subscribed', 'a@b.co');

    expect(PrecognitiveSubscribe::$ran)->toBe(1);
});

it('refuses to check a function with no form request, rather than run it', function () {
    $ran = false;
    app(CallableRegistry::class)->register('Newsletter.plain', function (array $data) use (&$ran) {
        $ran = true;
    });

    checkHost(['function' => 'Newsletter.plain', 'args' => [[]], 'validate' => ['email']])->assertStatus(500);

    expect($ran)->toBeFalse();
});

it('takes validate only as a list of names', function () {
    checkHost(['function' => 'Newsletter.subscribe', 'args' => [[]], 'validate' => 'email'])->assertStatus(400);
    checkHost(['function' => 'Newsletter.subscribe', 'args' => [[]], 'validate' => [1]])->assertStatus(400);

    expect(PrecognitiveSubscribe::$ran)->toBe(0);
});

it('lists the functions that can be checked', function () {
    expect(CallableRegistry::validating([
        'Newsletter.subscribe' => [PrecognitiveSubscribe::class, 'store'],
        'Newsletter.plain' => fn (array $data) => null,
        'Newsletter.closure' => fn (PrecognitiveSubscribeRequest $request) => null,
    ]))->toBe(['Newsletter.closure', 'Newsletter.subscribe']);
});

class PrecognitiveSubscribeRequest extends FormRequest
{
    public static bool $sawPrecognitive = false;

    public function authorize(): bool
    {
        return $this->input('name') !== 'blocked';
    }

    public function rules(): array
    {
        self::$sawPrecognitive = $this->isPrecognitive();

        return [
            'email' => ['required', 'email'],
            'name' => ['required', 'string'],
            'tags.*' => ['required', 'string'],
        ];
    }
}

class PrecognitiveSubscribe
{
    public static int $ran = 0;

    public function store(PrecognitiveSubscribeRequest $request): array
    {
        self::$ran++;

        return ['subscribed' => $request->input('email')];
    }
}
