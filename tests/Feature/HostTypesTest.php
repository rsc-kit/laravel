<?php

namespace Tests\Feature\HostTypesFixtures {
    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Collection;
    use Illuminate\Validation\Rule;

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
            public ?Carbon $shippedAt = null,
            public ?\DateTimeImmutable $raw = null,
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

    class SendContact extends FormRequest
    {
        public function rules(): array
        {
            return [
                'email' => 'required|email|max:255',
                'site' => 'nullable|url',
                'born' => 'date',
                'code' => 'string|size:6',
                'seats' => 'integer|between:1,10',
                'rating' => 'integer|in:1,2,3',
                'price' => 'numeric|min:0.5',
                'topic' => ['required', Rule::in(['sales', 'after hours'])],
                'plan' => 'in:free,paid',
                'slug' => ['string', 'regex:/^[a-z,]+$/'],
                'tags' => 'array|min:1',
                'check' => ['string', fn () => null],
            ];
        }
    }

    class Orders
    {
        public function contact(SendContact $request): void {}

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

        public function update(StoreOrder $request, string $order): void {}

        public function untyped($anything)
        {
            return $anything;
        }
    }
}

namespace {
    use Illuminate\Contracts\Pagination\CursorPaginator;
    use Illuminate\Pagination\LengthAwarePaginator;
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
            'Orders.contact' => [Orders::class, 'contact'],
            'Orders.cancel' => [Orders::class, 'cancel'],
            'Orders.update' => [Orders::class, 'update'],
            'Orders.untyped' => [Orders::class, 'untyped'],
            'Inline.add' => fn (int $a, int $b): int => $a + $b,
            'Pages.byCursor' => fn (): CursorPaginator => throw new LogicException,
            'Pages.counted' => fn (): LengthAwarePaginator => throw new LogicException,
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

    it('types a page as it encodes: next_cursor by cursor, total when counted', function () {
        $types = describeOrders()['types'];
        $byCursor = $types['Pages.byCursor']['result'];
        $counted = $types['Pages.counted']['result'];

        expect($byCursor['properties']['data'])->toBe(['type' => 'array', 'items' => []])
            ->and($byCursor['properties']['next_cursor'])->toBe(['anyOf' => [['type' => 'string'], ['type' => 'null']]])
            ->and($byCursor['properties'])->not->toHaveKey('total')
            ->and($byCursor['required'])->toContain('data', 'next_cursor', 'prev_cursor');

        expect($counted['properties']['total'])->toBe(['type' => 'integer'])
            ->and($counted['properties'])->not->toHaveKey('next_cursor')
            ->and($counted['required'])->toContain('data', 'total', 'last_page');
    });

    it('types the arguments after a FormRequest as themselves: a panel\'s update takes the record\'s key', function () {
        $params = describeOrders()['types']['Orders.update']['params'];

        expect($params)->toHaveCount(2)
            ->and($params[0]['type'])->toBe('object')
            ->and(array_keys($params[0]['properties']))->toBe(['name', 'quantity', 'gift', 'tags'])
            ->and($params[1])->toBe(['type' => 'string']);
    });

    it('describes a plain class result by its public properties, and an enum by its values', function () {
        $described = describeOrders();

        expect($described['types']['Orders.find']['result'])->toBe(['$ref' => '#/defs/OrderData']);

        $order = $described['defs']['OrderData'];

        expect($order['properties']['status'])->toBe(['type' => 'string', 'enum' => ['open', 'closed']])
            ->and($order['properties']['parent'])->toBe(['anyOf' => [['$ref' => '#/defs/OrderData'], ['type' => 'null']]])
            // json_encode writes every public property, null or not.
            ->and($order['required'])->toBe(['id', 'note', 'number', 'parent', 'raw', 'shippedAt', 'status']);

        // Carbon is an ISO string, or null. PHP's own DateTime encodes as an
        // object of its parts, so it is left open.
        expect($order['properties']['shippedAt'])->toBe(['anyOf' => [['type' => 'string', 'format' => 'date-time'], ['type' => 'null']]])
            ->and($order['properties']['raw'])->toBe(['anyOf' => [[], ['type' => 'null']]]);
    });

    it('reads a form request\'s fields from its rules, as the call\'s one argument', function () {
        $store = describeOrders()['types']['Orders.store'];

        expect($store['params'])->toHaveCount(1);

        $fields = $store['params'][0];

        expect($fields['properties']['quantity'])->toBe(['type' => 'integer', 'minimum' => 1])
            ->and($fields['properties']['gift'])->toBe(['type' => 'boolean'])
            ->and($fields['properties']['tags']['anyOf'][1])->toBe(['type' => 'null'])
            ->and($fields['properties'])->not->toHaveKey('tags.*')
            ->and($fields['required'])->toBe(['name', 'quantity']);
    });

    it('reads what a form can tell the visitor from the rules, and only that', function () {
        $fields = describeOrders()['types']['Orders.contact']['params'][0]['properties'];

        expect($fields['email'])->toEqual(['format' => 'email', 'maxLength' => 255, 'type' => 'string'])
            ->and($fields['site']['anyOf'][0])->toEqual(['format' => 'uri', 'type' => 'string'])
            ->and($fields['born'])->toEqual(['format' => 'date', 'type' => 'string'])
            ->and($fields['code'])->toEqual(['minLength' => 6, 'maxLength' => 6, 'type' => 'string'])
            ->and($fields['seats'])->toEqual(['minimum' => 1, 'maximum' => 10, 'type' => 'integer'])
            ->and($fields['rating'])->toEqual(['enum' => [1, 2, 3], 'type' => 'integer'])
            ->and($fields['price'])->toEqual(['minimum' => 0.5, 'type' => 'number'])
            ->and($fields['topic'])->toEqual(['enum' => ['sales', 'after hours'], 'type' => 'string'])
            ->and($fields['plan'])->toEqual(['enum' => ['free', 'paid'], 'type' => 'string'])
            ->and($fields['slug'])->toEqual(['type' => 'string'])
            ->and($fields['tags']['type'])->toBe('array')
            ->and($fields['tags'])->not->toHaveKey('minLength')
            ->and($fields['check'])->toEqual(['type' => 'string']);
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
