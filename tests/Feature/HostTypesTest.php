<?php

namespace Tests\Feature\HostTypesFixtures {
    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Support\Collection;

    enum Status: string
    {
        case Open = 'open';
        case Closed = 'closed';
    }

    final class OrderData
    {
        public function __construct(
            public int $id,
            public string $number,
            public Status $status,
            public ?OrderData $parent = null,
            public ?string $note = null,
        ) {}
    }

    class StoreOrder extends FormRequest
    {
        public function rules(): array
        {
            return [
                'name' => 'required|string|max:20',
                'quantity' => ['required', 'integer', 'min:1'],
                'gift' => 'boolean',
                'tags' => 'nullable|array',
                'tags.*' => 'string',
            ];
        }
    }

    class Orders
    {
        public function recent(int $limit = 5): Collection
        {
            return collect();
        }

        public function find(int $id, ?array $options = null): OrderData
        {
            return new OrderData($id, 'A-1', Status::Open);
        }

        public function tag(string $separator, string ...$tags): string
        {
            return implode($separator, $tags);
        }

        public function store(StoreOrder $request): OrderData
        {
            return new OrderData(1, 'A-1', Status::Open);
        }

        public function cancel(int $id): void {}

        public function untyped($anything)
        {
            return $anything;
        }
    }
}

namespace {
    use Illuminate\Support\Facades\Artisan;
    use RscKit\Support\HostTypes;
    use Tests\Feature\HostTypesFixtures\Orders;

    function describeOrders(): array
    {
        return json_decode(json_encode(HostTypes::describe([
            'Orders.recent' => [Orders::class, 'recent'],
            'Orders.find' => [Orders::class, 'find'],
            'Orders.tag' => [Orders::class, 'tag'],
            'Orders.store' => [Orders::class, 'store'],
            'Orders.cancel' => [Orders::class, 'cancel'],
            'Orders.untyped' => [Orders::class, 'untyped'],
            'Inline.add' => fn (int $a, int $b): int => $a + $b,
        ])), true);
    }

    it('types positional parameters, a trailing default as optional, and a variadic rest', function () {
        $types = describeOrders()['types'];

        expect($types['Inline.add'])->toBe([
            'params' => [['type' => 'integer'], ['type' => 'integer']],
            'result' => ['type' => 'integer'],
        ]);

        expect($types['Orders.find']['optional'])->toBe(1)
            ->and($types['Orders.tag']['rest'])->toBe(['type' => 'string'])
            ->and($types['Orders.tag']['params'])->toBe([['type' => 'string']]);
    });

    it('describes a plain class result by its public properties, and an enum by its values', function () {
        $described = describeOrders();

        expect($described['types']['Orders.find']['result'])->toBe(['$ref' => '#/defs/OrderData']);

        $order = $described['defs']['OrderData'];

        expect($order['properties']['status'])->toBe(['type' => 'string', 'enum' => ['open', 'closed']])
            ->and($order['properties']['parent'])->toBe(['anyOf' => [['$ref' => '#/defs/OrderData'], ['type' => 'null']]])
            // json_encode writes every public property, null or not.
            ->and($order['required'])->toBe(['id', 'note', 'number', 'parent', 'status']);
    });

    it('reads a form request\'s fields from its rules, as the call\'s one argument', function () {
        $store = describeOrders()['types']['Orders.store'];

        expect($store['params'])->toHaveCount(1);

        $fields = $store['params'][0];

        expect($fields['properties']['quantity'])->toBe(['type' => 'integer'])
            ->and($fields['properties']['gift'])->toBe(['type' => 'boolean'])
            ->and($fields['properties']['tags']['anyOf'][1])->toBe(['type' => 'null'])
            ->and($fields['properties'])->not->toHaveKey('tags.*')
            ->and($fields['required'])->toBe(['name', 'quantity']);
    });

    it('leaves open what PHP cannot know, and says nothing for void', function () {
        $types = describeOrders()['types'];

        // A collection's contents are decided at runtime.
        expect($types['Orders.recent']['result'])->toBe([])
            ->and($types['Orders.untyped']['params'])->toBe([[]])
            ->and($types['Orders.cancel'])->not->toHaveKey('result');
    });

    it('is written to the manifest the build reads', function () {
        Artisan::call('rsc:host-manifest', ['--print' => true]);

        $manifest = json_decode(Artisan::output(), true);

        expect($manifest)->toHaveKeys(['actions', 'functions', 'types', 'defs']);
    });
}
