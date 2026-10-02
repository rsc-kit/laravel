<?php

namespace RscKit\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Foundation\Http\FormRequest;
use JsonSerializable;
use ReflectionClass;
use ReflectionEnum;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use UnitEnum;

/**
 * What each callable takes and returns, as the JSON Schema the build turns
 * into TypeScript - so the app's rpc('Orders.recent', 5) is typed from the
 * PHP method, and a misspelt argument fails its typecheck.
 *
 * Only what PHP can say for certain. A parameter is positional, as the
 * registry calls it; a FormRequest in front is the call's one argument, its
 * fields read from rules(). A result is typed when it is a scalar, a backed
 * enum, or a plain class whose public properties are what json_encode writes.
 * An array, a model, a collection - anything whose shape is decided at runtime
 * - is left open rather than guessed at.
 */
class HostTypes
{
    /** @var array<string, array<string, mixed>> */
    private array $defs = [];

    /** @var array<class-string, string> */
    private array $named = [];

    /**
     * @param  array<string, array{class-string, string}|class-string|Closure>  $callables
     * @return array{types: array<string, array<string, mixed>>, defs: array<string, array<string, mixed>>}
     */
    public static function describe(array $callables): array
    {
        $self = new self;
        $types = [];

        foreach ($callables as $name => $callable) {
            try {
                $types[$name] = $self->signature(self::reflect($callable));
            } catch (Throwable) {
                // A callable reflection cannot read stays untyped; it is
                // still callable, and the app's call still compiles.
            }
        }

        ksort($types);
        ksort($self->defs);

        return ['types' => $types, 'defs' => $self->defs];
    }

    /** @param  array{class-string, string}|class-string|Closure  $callable */
    private static function reflect(array|string|Closure $callable): ReflectionFunctionAbstract
    {
        return match (true) {
            $callable instanceof Closure => new ReflectionFunction($callable),
            is_string($callable) => new ReflectionMethod($callable, '__invoke'),
            default => new ReflectionMethod($callable[0], $callable[1]),
        };
    }

    /** @return array<string, mixed> */
    private function signature(ReflectionFunctionAbstract $fn): array
    {
        $params = $fn->getParameters();
        $signature = ['params' => []];

        $first = $params[0] ?? null;
        $firstType = $first?->getType();

        // The call's one argument, validated by the request's rules.
        if ($firstType instanceof ReflectionNamedType && ! $firstType->isBuiltin()
            && is_subclass_of($firstType->getName(), FormRequest::class)) {
            $signature['params'][] = $this->formRequest($firstType->getName());
        } else {
            $optional = 0;

            foreach ($params as $param) {
                if ($param->isVariadic()) {
                    $signature['rest'] = $this->of($param->getType());

                    continue;
                }

                $signature['params'][] = $this->of($param->getType());
                $optional = $param->isOptional() ? $optional + 1 : 0;
            }

            if ($optional > 0) {
                $signature['optional'] = $optional;
            }
        }

        $return = $fn->getReturnType();

        if (! ($return instanceof ReflectionNamedType && in_array($return->getName(), ['void', 'never'], true))) {
            $signature['result'] = $this->of($return);
        }

        return $signature;
    }

    /** @return array<string, mixed> */
    private function of(?ReflectionType $type): array
    {
        if ($type === null || $type instanceof ReflectionIntersectionType) {
            return [];
        }

        if ($type instanceof ReflectionUnionType) {
            return ['anyOf' => array_map(fn (ReflectionType $t) => $this->of($t), $type->getTypes())];
        }

        /** @var ReflectionNamedType $type */
        $schema = $this->named($type->getName());

        return $type->allowsNull() && $type->getName() !== 'null' && $type->getName() !== 'mixed'
            ? ['anyOf' => [$schema, ['type' => 'null']]]
            : $schema;
    }

    /** @return array<string, mixed> */
    private function named(string $name): array
    {
        return match ($name) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'string' => ['type' => 'string'],
            'bool' => ['type' => 'boolean'],
            'true', 'false' => ['type' => 'boolean'],
            'null' => ['type' => 'null'],
            default => class_exists($name) || enum_exists($name) ? $this->class($name) : [],
        };
    }

    /**
     * A class as what json_encode writes for it, when that is knowable.
     *
     * @param  class-string  $class
     * @return array<string, mixed>
     */
    private function class(string $class): array
    {
        if (is_subclass_of($class, BackedEnum::class)) {
            return $this->enum($class);
        }

        // Carbon encodes as an ISO 8601 string. It is JsonSerializable, so
        // without this it was left open: unknown, on every timestamp a
        // model or a data object returns. PHP's own DateTime is not this - it
        // encodes as an object - and stays open below.
        if (is_a($class, CarbonInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        // Its own encoding, or a runtime one: a model, a collection, a
        // resource. What it holds is not on the class.
        if (is_subclass_of($class, UnitEnum::class)
            || is_subclass_of($class, JsonSerializable::class)
            || is_subclass_of($class, Arrayable::class)
            || is_subclass_of($class, Jsonable::class)) {
            return [];
        }

        if (isset($this->named[$class])) {
            return ['$ref' => '#/defs/'.$this->named[$class]];
        }

        $reflection = new ReflectionClass($class);

        // PHP's own classes - DateTime, ArrayObject - encode from internals,
        // not from public properties: there is nothing here to describe.
        if ($reflection->isInternal()) {
            return [];
        }

        $name = $reflection->getShortName();

        // Two namespaces' Order are two types.
        if (isset($this->defs[$name])) {
            $name = str_replace('\\', '', $reflection->getNamespaceName()).$name;
        }

        $this->named[$class] = $name;
        $this->defs[$name] = [];

        $properties = [];
        $required = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $properties[$property->getName()] = $this->of($property->getType());

            // json_encode writes every public property, null or not: each is
            // always there, and a nullable one is typed as such.
            $required[] = $property->getName();
        }

        sort($required);

        $this->defs[$name] = array_filter([
            'type' => 'object',
            'properties' => (object) $properties,
            'required' => $required,
        ], fn ($v) => $v !== []);

        return ['$ref' => '#/defs/'.$name];
    }

    /**
     * @param  class-string<BackedEnum>  $class
     * @return array<string, mixed>
     */
    private function enum(string $class): array
    {
        $backing = (string) (new ReflectionEnum($class))->getBackingType();

        return [
            'type' => $backing === 'int' ? 'integer' : 'string',
            'enum' => array_map(fn (BackedEnum $case) => $case->value, $class::cases()),
        ];
    }

    /**
     * A form request's fields, from its rules. Best effort: rules() may want a
     * user or a route that a build has not got, and then the argument is an
     * object of anything rather than a guess.
     *
     * @param  class-string<FormRequest>  $class
     * @return array<string, mixed>
     */
    private function formRequest(string $class): array
    {
        try {
            $rules = app()->build($class)->rules();
        } catch (Throwable) {
            return ['type' => 'object', 'additionalProperties' => (object) []];
        }

        $properties = [];
        $required = [];

        foreach ($rules as $field => $rule) {
            // A nested or wildcard rule (items.*.name) describes something
            // inside a field; the field itself is described by its own rule.
            if (! is_string($field) || str_contains($field, '.')) {
                continue;
            }

            $list = is_string($rule) ? explode('|', $rule) : array_filter((array) $rule, 'is_string');
            $names = array_map(fn (string $r) => strtolower(explode(':', $r)[0]), $list);

            $type = match (true) {
                in_array('integer', $names, true) => ['type' => 'integer'],
                in_array('numeric', $names, true) || in_array('decimal', $names, true) => ['type' => 'number'],
                in_array('boolean', $names, true) || in_array('accepted', $names, true) => ['type' => 'boolean'],
                in_array('array', $names, true) || in_array('list', $names, true) => ['type' => 'array', 'items' => (object) []],
                in_array('file', $names, true) || in_array('image', $names, true) => [],
                default => ['type' => 'string'],
            };

            $properties[$field] = in_array('nullable', $names, true) && $type !== []
                ? ['anyOf' => [$type, ['type' => 'null']]]
                : $type;

            if (in_array('required', $names, true)) {
                $required[] = $field;
            }
        }

        sort($required);

        return array_filter([
            'type' => 'object',
            'properties' => (object) $properties,
            'required' => $required,
        ], fn ($v) => $v !== []);
    }
}
