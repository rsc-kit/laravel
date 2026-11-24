<?php

namespace Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use RscKit\Attributes\Authenticated;
use RscKit\Attributes\Can;

class BuildForm extends FormRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return [];
    }
}

class BuildTakesRequest
{
    public function __construct(private Request $request) {}

    public function agent(): ?string
    {
        return $this->request->userAgent();
    }
}

class BuildTakesSession
{
    public function __construct(private Session $session) {}

    public function cart(): mixed
    {
        return $this->session->get('cart', []);
    }
}

class BuildTakesUser
{
    public function __construct(private ?Authenticatable $user) {}

    public function name(): ?string
    {
        return $this->user?->getAuthIdentifier();
    }
}

/** What an app binds once and reads everywhere. */
class BuildTeam
{
    private mixed $team = false;

    public function current(): mixed
    {
        return $this->team === false ? $this->team = session('team') : $this->team;
    }
}

class BuildGuarded
{
    #[Authenticated]
    public function signedIn(): string
    {
        throw new \LogicException('ran');
    }

    #[Can('view')]
    public function allowed(): string
    {
        throw new \LogicException('ran');
    }

    #[Middleware('throttle:1')]
    public function throttled(): string
    {
        throw new \LogicException('ran');
    }
}

#[Authenticated]
abstract class BuildGuardedParent
{
    public function inherited(): string
    {
        throw new \LogicException('ran');
    }
}

class BuildGuardedChild extends BuildGuardedParent {}
