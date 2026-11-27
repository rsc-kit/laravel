<?php

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;

/**
 * An action's guards judging the page it was posted from.
 *
 * The call is a POST from the renderer to /__rsc/host-call, so `signed` on an
 * action checked that url and refused every link. The renderer sends the page
 * the form was on beside the call, as X-Rsc-Page, and the guards run against
 * a request for it - through the real endpoint, the way the renderer reaches it.
 */
class SignedAction
{
    #[Middleware('signed')]
    public function verify(): string
    {
        return 'verified';
    }
}

class PageParamsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('id') === '7';
    }

    public function rules(): array
    {
        return [];
    }
}

class ReadsPageParams
{
    public function verify(PageParamsRequest $request): array
    {
        return [$request->route('id'), $request->route('hash')];
    }

    public function withOwn(PageParamsRequest $request, string $hash): array
    {
        return [$request->route('id'), $request->route('hash')];
    }
}

beforeEach(function () {
    config()->set('rsc.host_call_secret', 'page-secret');
    Route::post('/__rsc/host-call', HostCallController::class)->middleware('web');
    Route::get('/verify-email/{id}/{hash}', fn () => null)->name('verification.verify');
    app('router')->getRoutes()->refreshNameLookups();

    app()->forgetInstance(HostCallDispatcher::class);
    $registry = app(CallableRegistry::class);
    $registry->register('Account.verify', [SignedAction::class, 'verify']);
    $registry->register('Account.params', [ReadsPageParams::class, 'verify']);
    $registry->register('Account.own', [ReadsPageParams::class, 'withOwn']);
});

/** @param  array<string, mixed>|string|null  $page  what X-Rsc-Page carries */
function callFromPage(string $function, array $args, array|string|null $page): TestResponse
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RSC_HOST_SECRET' => 'page-secret',
    ];

    if ($page !== null) {
        $server['HTTP_X_RSC_PAGE'] = is_string($page) ? $page : json_encode($page);
    }

    return test()->call('POST', '/__rsc/host-call', [], [], [], $server, json_encode(['function' => $function, 'args' => $args]));
}

describe('#[Middleware(\'signed\')] on an action', function () {
    it('runs when the page it was posted from is a signed link', function () {
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => 7, 'hash' => 'abc']);

        callFromPage('Account.verify', [], ['url' => $url, 'params' => ['id' => '7', 'hash' => 'abc']])
            ->assertOk()
            ->assertJson(['result' => 'verified']);
    });

    it('refuses a link whose signature was tampered with', function () {
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => 7, 'hash' => 'abc']);

        callFromPage('Account.verify', [], ['url' => str_replace('/7/', '/8/', $url), 'params' => ['id' => '8', 'hash' => 'abc']])
            ->assertForbidden();
    });

    it('refuses a link that expired', function () {
        $url = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => 7, 'hash' => 'abc']);

        callFromPage('Account.verify', [], ['url' => $url, 'params' => []])->assertForbidden();
    });

    // Fails closed: with no page there is only the endpoint's url to check,
    // and it carries no signature.
    it('refuses when no page was sent, or one that is not a url', function () {
        callFromPage('Account.verify', [], null)->assertForbidden();
        callFromPage('Account.verify', [], 'not json')->assertForbidden();
        callFromPage('Account.verify', [], ['url' => '/verify-email/7/abc'])->assertForbidden();
    });
});

describe('the request an action takes', function () {
    it('has the page\'s route params, as EmailVerificationRequest reads them', function () {
        callFromPage('Account.params', [[]], ['url' => 'http://localhost/verify-email/7/abc', 'params' => ['id' => '7', 'hash' => 'abc']])
            ->assertOk()
            ->assertJson(['result' => ['7', 'abc']]);
    });

    it('is refused by its authorize() when the page is someone else\'s', function () {
        callFromPage('Account.params', [[]], ['url' => 'http://localhost/verify-email/8/abc', 'params' => ['id' => '8', 'hash' => 'abc']])
            ->assertForbidden();
    });

    it('takes the method\'s own arguments over the page\'s', function () {
        callFromPage('Account.own', [[], 'mine'], ['url' => 'http://localhost/verify-email/7/abc', 'params' => ['id' => '7', 'hash' => 'abc']])
            ->assertOk()
            ->assertJson(['result' => ['7', 'mine']]);
    });

    it('drops a param that is not a string', function () {
        callFromPage('Account.params', [[]], ['url' => 'http://localhost/verify-email/7/abc', 'params' => ['id' => 7, 'hash' => ['abc']]])
            ->assertForbidden();
    });
});
