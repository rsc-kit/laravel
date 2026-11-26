<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallDispatcher;
use RscKit\Revalidation;
use RscKit\Support\HostTypes;

/**
 * logout(Request $request), as a controller's: the call's own request, the
 * form's fields its input, the visitor's session and user. The endpoint's
 * request is the envelope, and a method handed it found nothing it was sent -
 * so it was handed nothing, and PHP answered with a TypeError.
 */
class PlainRequestService
{
    public function name(): string
    {
        return 'service';
    }
}

class PlainRequestOfMine extends Request
{
    public function email(): ?string
    {
        return $this->input('email');
    }
}

class PlainRequestActions
{
    public function input(Request $request): array
    {
        return $request->all();
    }

    public function logout(Request $request): bool
    {
        $request->session()->invalidate();

        return $request->session()->has('team');
    }

    public function visitor(Request $request): array
    {
        return [$request->ip(), $request->user()?->getAuthIdentifier()];
    }

    public function served(Request $request, PlainRequestService $service): string
    {
        return $service->name().' '.$request->input('email');
    }

    public function keyed(Request $request, string $order): array
    {
        return [$request->route('order'), $order, $request->input('note')];
    }

    public function mine(PlainRequestOfMine $request): ?string
    {
        return $request->email();
    }
}

function plainRequest(string $method, array $args): array
{
    $registry = new CallableRegistry(app());
    $registry->register('it', [PlainRequestActions::class, $method]);

    return (new HostCallDispatcher($registry, app(Revalidation::class), 's3cret'))
        ->dispatch(['function' => 'it', 'args' => $args]);
}

function plainRequestParams(string $method): array
{
    return json_decode(json_encode(HostTypes::describe(['it' => [PlainRequestActions::class, $method]])), true)['types']['it'];
}

beforeEach(function () {
    $call = Request::create('/__rsc/host-call', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
    $session = new Store('rsc', new ArraySessionHandler(10));
    $session->put('team', 7);
    $call->setLaravelSession($session);
    app()->instance('request', $call);
});

it("is the form's fields, not the host call's envelope", function () {
    expect(plainRequest('input', [['email' => 'a@b.test']])['reply']['result'])->toBe(['email' => 'a@b.test']);
});

it('is an empty form when nothing was sent, as from a button', function () {
    expect(plainRequest('input', [])['reply']['result'])->toBe([]);
});

it("carries the visitor's session, so logging out ends it", function () {
    expect(plainRequest('logout', [[]])['reply']['result'])->toBeFalse()
        ->and(app('request')->session()->has('team'))->toBeFalse();
});

it("carries the visitor's address and user", function () {
    Auth::setUser((new User)->forceFill(['id' => 5]));
    app('request')->setUserResolver(fn () => Auth::user());

    expect(plainRequest('visitor', [])['reply']['result'])->toBe(['203.0.113.9', 5]);
});

it('takes services beside it, and a key after it as a route parameter', function () {
    expect(plainRequest('served', [['email' => 'a@b.test']])['reply']['result'])->toBe('service a@b.test')
        ->and(plainRequest('keyed', [['note' => 'gift'], 'A-1'])['reply']['result'])->toBe(['A-1', 'A-1', 'gift']);
});

it("builds the app's own Request subclass", function () {
    expect(plainRequest('mine', [['email' => 'a@b.test']])['reply']['result'])->toBe('a@b.test');
});

it('is typed as the form, which may be left out when nothing follows it', function () {
    expect(plainRequestParams('input'))->toMatchArray(['params' => [['type' => 'object']], 'optional' => 1])
        ->and(plainRequestParams('served'))->toMatchArray(['params' => [['type' => 'object']], 'optional' => 1])
        ->and(plainRequestParams('keyed'))->toBe(['params' => [['type' => 'object'], ['type' => 'string']], 'result' => []]);
});
