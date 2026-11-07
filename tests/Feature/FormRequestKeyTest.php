<?php

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;

/**
 * A panel's update, update(values, id): the record's key after the form.
 *
 * The method takes it by name - update(UpdateProductRequest $request, string
 * $product) - and the FormRequest reads it as its route parameter, so
 * Rule::unique()->ignore($this->route('product')) works as it does on
 * PUT /products/{product}.
 */
class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Unique among the other products: the one being edited keeps its own name.
        $others = ['1' => 'Lamp', '2' => 'Desk'];
        unset($others[(string) $this->route('product')]);

        return ['name' => ['required', 'string', Rule::notIn(array_values($others))]];
    }
}

class UpdatesProduct
{
    public function __invoke(UpdateProductRequest $request, string $product): array
    {
        return ['product' => $product, 'name' => $request->validated('name'), 'route' => $request->route('product')];
    }
}

beforeEach(function () {
    config()->set('rsc.host_call_secret', 'key-secret');
    Route::post('/__rsc/host-call', HostCallController::class)->middleware('web');
    app()->forgetInstance(HostCallDispatcher::class);
    app(CallableRegistry::class)->register('Products.update', UpdatesProduct::class);
});

function updateProduct(array $values, string $id)
{
    return test()->call('POST', '/__rsc/host-call', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RSC_HOST_SECRET' => 'key-secret',
    ], json_encode(['function' => 'Products.update', 'args' => [$values, $id]]));
}

it('hands the method the key after the validated form', function () {
    updateProduct(['name' => 'Lamp'], '1')
        ->assertOk()
        ->assertJson(['result' => ['product' => '1', 'name' => 'Lamp', 'route' => '1']]);
});

it('lets the rules read the key as the route parameter', function () {
    // Desk is product 2's name, so product 1 may not take it.
    updateProduct(['name' => 'Desk'], '1')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name'], 'validationErrors');
});

it('still validates a form with nothing after it', function () {
    app(CallableRegistry::class)->register('Products.store', fn (UpdateProductRequest $request) => $request->validated());
    app()->forgetInstance(HostCallDispatcher::class);

    test()->call('POST', '/__rsc/host-call', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RSC_HOST_SECRET' => 'key-secret',
    ], json_encode(['function' => 'Products.store', 'args' => [['name' => 'Chair']]]))
        ->assertOk()
        ->assertJson(['result' => ['name' => 'Chair']]);
});
