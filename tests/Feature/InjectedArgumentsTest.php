<?php

use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallDispatcher;
use RscKit\Revalidation;
use RscKit\Support\HostTypes;

/**
 * A callable takes services as a controller's method does, and the call's
 * arguments are what is left.
 */
class InjectedSignup extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['email' => 'required|email'];
    }
}

class RegistersSomeone
{
    public static int $made = 0;

    public function __construct()
    {
        self::$made++;
    }

    public function execute(string $email): string
    {
        return "registered {$email}";
    }
}

interface SendsWelcome
{
    public function to(string $email): string;
}

class WelcomeByMail implements SendsWelcome
{
    public function to(string $email): string
    {
        return "welcomed {$email}";
    }
}

class InjectedAddress
{
    public string $street = '';
}

/** Public, but given a service: not data. */
class ExposesItsMailer
{
    public function __construct(public WelcomeByMail $mailer) {}
}

class InjectedProduct extends Model {}

enum InjectedSize: string
{
    case Small = 's';
}

class InjectedActions
{
    public function register(InjectedSignup $request, RegistersSomeone $register): string
    {
        return $register->execute($request->validated('email'));
    }

    public function serviceFirst(RegistersSomeone $register, InjectedSignup $request): string
    {
        return $register->execute($request->validated('email'));
    }

    public function between(string $email, RegistersSomeone $register, int $times = 1, string ...$tags): array
    {
        return [$register->execute($email), $times, $tags];
    }

    public function keyed(InjectedSignup $request, RegistersSomeone $register, string $id): array
    {
        return [$request->route('id'), $id];
    }

    public function contract(SendsWelcome $welcome, string $email): string
    {
        return $welcome->to($email);
    }

    public function exposes(ExposesItsMailer $service): string
    {
        return $service->mailer->to('a@b.test');
    }

    public function me(#[CurrentUser] User $user, string $greeting): string
    {
        return "{$greeting} {$user->getAuthIdentifier()}";
    }

    public function maybeMe(#[CurrentUser] ?User $user): bool
    {
        return $user === null;
    }

    public function configured(#[Config('app.name')] string $name): string
    {
        return $name;
    }

    public function model(InjectedProduct $product): void {}

    public function date(CarbonImmutable $at): void {}

    public function size(InjectedSize $size): void {}

    public function data(InjectedAddress $address): void {}

    public function rows(Collection $rows): void {}

    public function http(Request $request): void {}

    public function optional(?RegistersSomeone $register = null, string $email = 'none'): array
    {
        return [$register, $email];
    }
}

function injected(string $method, array $args, ?array $validate = null): array
{
    $registry = new CallableRegistry(app());
    $registry->register('it', [InjectedActions::class, $method]);

    return (new HostCallDispatcher($registry, app(Revalidation::class), 's3cret'))
        ->dispatch(['function' => 'it', 'args' => $args, ...($validate === null ? [] : ['validate' => $validate])]);
}

function injectedParams(string $method): array
{
    return json_decode(json_encode(HostTypes::describe(['it' => [InjectedActions::class, $method]])), true)['types']['it']['params'];
}

beforeEach(function () {
    RegistersSomeone::$made = 0;
    // A host call is a POST; a form request reads a GET's query instead.
    app()->instance('request', Request::create('/__rsc/host-call', 'POST'));
});

it('hands a service to the method after its form request', function () {
    expect(injected('register', [['email' => 'a@b.test']])['reply'])->toBe(['result' => 'registered a@b.test']);
});

it('finds the form request behind a service', function () {
    expect(injected('serviceFirst', [['email' => 'a@b.test']])['reply'])->toBe(['result' => 'registered a@b.test']);
    expect(CallableRegistry::validatesEarly([InjectedActions::class, 'serviceFirst']))->toBeTrue();
});

it('makes nothing for a form that is refused, or only checked', function () {
    expect(injected('register', [['email' => 'nobody']])['status'])->toBe(422);
    expect(injected('register', [['email' => 'a@b.test']], [])['status'])->toBe(200);
    expect(RegistersSomeone::$made)->toBe(0);
});

it('keeps the arguments around a service in order, with defaults and a variadic', function () {
    expect(injected('between', ['a@b.test'])['reply']['result'])->toBe(['registered a@b.test', 1, []]);
    expect(injected('between', ['a@b.test', 3, 'x', 'y'])['reply']['result'])->toBe(['registered a@b.test', 3, ['x', 'y']]);
});

it('gives the form request its key past a service', function () {
    expect(injected('keyed', [['email' => 'a@b.test'], '7'])['reply']['result'])->toBe(['7', '7']);
});

it('resolves an interface by its binding, and a service with public properties', function () {
    app()->bind(SendsWelcome::class, WelcomeByMail::class);

    expect(injected('contract', ['a@b.test'])['reply']['result'])->toBe('welcomed a@b.test');
    expect(injected('exposes', [])['reply']['result'])->toBe('welcomed a@b.test');
});

it('takes the signed-in user from #[CurrentUser], never from the call', function () {
    $user = new User;
    $user->id = 5;
    app(AuthFactory::class)->guard()->setUser($user);

    expect(injected('me', ['hello'])['reply']['result'])->toBe('hello 5');
    expect(injected('me', [['id' => 9], 'hello'])['status'])->toBe(500);
});

it('answers a guest 401 where #[CurrentUser] needs someone, and null where it does not', function () {
    expect(injected('me', ['hello'])['status'])->toBe(401);
    expect(injected('maybeMe', [['id' => 9]])['reply']['result'])->toBeTrue();
});

it('lets no argument stand in for a contextual attribute', function () {
    config()->set('app.name', 'Shop');

    expect(injected('configured', ['Mallory'])['reply']['result'])->toBe('Shop');
});

it('leaves data to the call rather than make it empty', function (string $method) {
    expect(injected($method, ['anything'])['status'])->toBe(500);
    expect(injected($method, [])['status'])->toBe(500);
    expect(injectedParams($method))->toHaveCount(1);
})->with(['model', 'date', 'size', 'data', 'rows', 'http']);

it('leaves a nullable or defaulted class to the call, as it was', function () {
    expect(injected('optional', [null, 'a@b.test'])['reply']['result'])->toBe([null, 'a@b.test']);
    expect(injected('optional', [])['reply']['result'])->toBe([null, 'none']);
});

it('types the call without its services', function () {
    expect(injectedParams('register'))->toHaveCount(1)
        ->and(injectedParams('register')[0]['properties'])->toHaveKey('email')
        ->and(injectedParams('serviceFirst'))->toBe(injectedParams('register'))
        ->and(injectedParams('between'))->toBe([['type' => 'string'], ['type' => 'integer']])
        ->and(injectedParams('keyed')[1])->toBe(['type' => 'string'])
        ->and(injectedParams('me'))->toBe([['type' => 'string']])
        ->and(injectedParams('configured'))->toBe([]);
});

it('injects into a closure too', function () {
    $registry = new CallableRegistry(app());
    $registry->register('it', fn (RegistersSomeone $register, string $email) => $register->execute($email));

    expect($registry->execute('it', ['a@b.test']))->toBe('registered a@b.test');
});
