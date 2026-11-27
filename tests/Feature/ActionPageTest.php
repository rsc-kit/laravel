<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

/** A record bound by route, with no database behind it: posts 1 and 2 exist. */
class PagePost implements UrlRoutable
{
    public function __construct(public string $id = '', public string $owner = '') {}

    public function getRouteKey(): mixed
    {
        return $this->id;
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function resolveRouteBinding($value, $field = null): ?self
    {
        return in_array($value, ['1', '2'], true) ? new self($value, $value === '1' ? 'ada' : 'grace') : null;
    }

    public function resolveChildRouteBinding($childType, $value, $field): ?self
    {
        return null;
    }
}

class PublishesPosts
{
    #[Middleware('can:publish,post')]
    public function publish(PagePost $post): string
    {
        return "published {$post->id}";
    }

    public function open(PagePost $post): string
    {
        return "opened {$post->id}";
    }
}

class SignsInByEmail
{
    #[Middleware('throttle:sign-in')]
    public function store(Request $request): ?string
    {
        return $request->input('email');
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
    $registry->register('Posts.publish', [PublishesPosts::class, 'publish']);
    $registry->register('Posts.open', [PublishesPosts::class, 'open']);
    $registry->register('Session.store', [SignsInByEmail::class, 'store']);

    // Ada's post is 1; whoever is asking is Ada.
    Gate::define('publish', fn (?object $user, PagePost $post) => $post->owner === 'ada');
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

describe('a model the action takes', function () {
    $from = fn (string $post) => ['url' => "http://localhost/posts/{$post}", 'params' => ['post' => $post]];

    // The guard and the method are handed one post - the page's - so there
    // is no id to send that the guard did not see.
    it('is the page\'s, bound once, and the one its guard was handed', function () use ($from) {
        callFromPage('Posts.publish', [], $from('1'))->assertOk()->assertJson(['result' => 'published 1']);
        callFromPage('Posts.publish', [], $from('2'))->assertForbidden();
    });

    it('is never an id the call sends', function () use ($from) {
        callFromPage('Posts.publish', ['2'], $from('1'))->assertOk()->assertJson(['result' => 'published 1']);
    });

    it('is a 404 when the page names no such record, or there is no page', function () use ($from) {
        callFromPage('Posts.open', [], $from('3'))->assertNotFound();
        callFromPage('Posts.open', [], null)->assertNotFound();
        callFromPage('Posts.open', [], ['url' => 'http://localhost/elsewhere', 'params' => []])->assertNotFound();
    });
});

// Fortify's throttle:login keys on the email the form posted. Read from a GET
// of the page, there was no email, and every account behind one address
// shared one bucket: a visitor mistyping their password locked out everyone
// else signing in from their office.
describe('a guard reading what the form posted', function () {
    beforeEach(function () {
        RateLimiter::for('sign-in', fn (Request $request) => Limit::perMinute(1)->by((string) $request->input('email')));
    });

    it('is handed the fields, as on the form\'s own post', function () {
        $page = ['url' => 'http://localhost/login', 'params' => []];

        callFromPage('Session.store', [['email' => 'ada@example.com']], $page)->assertOk()->assertJson(['result' => 'ada@example.com']);
        callFromPage('Session.store', [['email' => 'ada@example.com']], $page)->assertTooManyRequests();
        callFromPage('Session.store', [['email' => 'grace@example.com']], $page)->assertOk()->assertJson(['result' => 'grace@example.com']);
    });
});
