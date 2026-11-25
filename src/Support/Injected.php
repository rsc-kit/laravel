<?php

namespace RscKit\Support;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Attributes\Authenticated;
use Illuminate\Container\Util;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;
use ReflectionClass;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\HttpFoundation\Request;
use UnitEnum;

/**
 * Which of a callable's parameters are the container's, and which the call's.
 *
 * register(RegisterRequest $request, RegisterUser $register) takes a service,
 * as a controller's method does. The call's arguments are what is left, in
 * order: the endpoint, the types and the generated stub all leave the service
 * out, so a browser can neither send one nor move what it does send.
 *
 * Only what a call could never have sent: JSON holds no object, so a required
 * class parameter was a TypeError however it was called. A nullable or
 * defaulted one was not, and stays the call's.
 *
 * Read from the type alone, never from what the container happens to have
 * bound: the manifest is written in one process and the call answered in
 * another, and the two must not disagree about a position.
 */
class Injected
{
    /**
     * Made by the container and wrong: a model with no row behind it, the
     * moment it was made instead of the date that was sent, the host call's
     * envelope in place of the form. A TypeError says so; these would not.
     */
    private const NEVER = [
        Request::class,
        UrlRoutable::class,
        DateTimeInterface::class,
        SplFileInfo::class,
        UnitEnum::class,
        BackedEnum::class,
        Arrayable::class,
        Jsonable::class,
        JsonSerializable::class,
    ];

    /**
     * The parameters the call's arguments fill, in order.
     *
     * @return list<ReflectionParameter>
     */
    public static function leaves(ReflectionFunctionAbstract $fn): array
    {
        return array_values(array_filter($fn->getParameters(), fn (ReflectionParameter $p) => ! self::is($p)));
    }

    public static function is(ReflectionParameter $param): bool
    {
        // #[CurrentUser] User $user: said outright, so the type has no say.
        if (Util::getContextualAttributeFromDependency($param) !== null) {
            return true;
        }

        $type = $param->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || $type->allowsNull()
            || $param->isOptional() || $param->isVariadic()) {
            return false;
        }

        $class = $type->getName();

        if (! class_exists($class) && ! interface_exists($class)) {
            return false;
        }

        foreach (self::NEVER as $not) {
            if (is_a($class, $not, true)) {
                return false;
            }
        }

        return ! self::holdsData(new ReflectionClass($class));
    }

    /**
     * A class that is its public properties, which the container would make
     * empty, and PHP's own classes, which it would make blank.
     *
     * @param  ReflectionClass<object>  $class
     */
    private static function holdsData(ReflectionClass $class): bool
    {
        if ($class->isInterface()) {
            return false;
        }

        if ($class->isInternal()) {
            return true;
        }

        $properties = array_filter(
            $class->getProperties(ReflectionProperty::IS_PUBLIC),
            fn (ReflectionProperty $p) => ! $p->isStatic(),
        );

        if ($properties === []) {
            return false;
        }

        // A service may expose what it was given. Data is given nothing the
        // container could make.
        foreach ($class->getConstructor()?->getParameters() ?? [] as $param) {
            if (Util::getParameterClassName($param) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The whole argument list: the call's arguments, with what the container
     * makes put where the callable takes it.
     *
     * @param  array<int, mixed>  $args
     * @return array<int, mixed>
     */
    public static function fill(ReflectionFunctionAbstract $fn, array $args, Container $container): array
    {
        $params = $fn->getParameters();

        if (count(self::leaves($fn)) === count($params)) {
            return $args;
        }

        $filled = [];

        foreach ($params as $param) {
            if (self::is($param)) {
                $filled[] = self::resolve($param, $container);
            } elseif ($param->isVariadic()) {
                return [...$filled, ...$args];
            } elseif ($args !== []) {
                $filled[] = array_shift($args);
            } elseif ($param->isDefaultValueAvailable()) {
                $filled[] = $param->getDefaultValue();
            } else {
                // Too few, and PHP's own error names the method and the count.
                return $filled;
            }
        }

        return $filled;
    }

    private static function resolve(ReflectionParameter $param, Container $container): mixed
    {
        $attribute = Util::getContextualAttributeFromDependency($param);

        if ($attribute === null) {
            return $container->make($param->getType()->getName());
        }

        $resolved = $container->resolveFromAttribute($attribute, $param);

        // A guest, where the method asks for someone. The container would go on
        // to make the type, and an empty User is not who is signed in.
        if ($resolved === null && ! $param->allowsNull() && is_a($attribute->getName(), Authenticated::class, true)) {
            throw new AuthenticationException;
        }

        $container->fireAfterResolvingAttributeCallbacks($param->getAttributes(), $resolved);

        return $resolved;
    }
}
