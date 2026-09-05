<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use BackedEnum;
use Closure;
use DateTimeInterface;
use IndexNowKit\Attribute\Param\Accessor;
use IndexNowKit\Attribute\Param\Call;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\Param\Formatted;
use IndexNowKit\Attribute\Param\ParamValue;
use IndexNowKit\Attribute\Param\Placeholder;
use IndexNowKit\Attribute\Param\Value;
use IndexNowKit\Exception\ConfigurationException;
use Stringable;

/**
 * Reads rule params off an object. A plain string is the accessor DSL (property, getter, is/has method,
 * dotted path, "self"); a ParamValue is one of the typed sources. Extraction runs once per generated URL,
 * so Placeholder::Locale / Placeholder::Host resolve to the URL being built.
 *
 * Public for adapters that evaluate `params` or `when` outside AttributeUrlResolver.
 */
final class ParamExtractor
{
    public const SELF = 'self';

    /** @var array<class-string<SubjectReaderInterface>, SubjectReaderInterface> */
    private static array $readers = [];

    private function __construct() {}

    /**
     * Registers a reader for objects the DSL cannot see into (Eloquent attributes, CMS fields). One instance per
     * class: registering the same class again replaces it. Adapters call this once, at boot.
     */
    public static function registerReader(SubjectReaderInterface $reader): void
    {
        self::$readers[$reader::class] = $reader;
    }

    /**
     * @param class-string<SubjectReaderInterface> $class
     */
    public static function unregisterReader(string $class): void
    {
        unset(self::$readers[$class]);
    }

    /**
     * @param array<string, string|ParamValue> $params routeParam => source
     *
     * @return array<string, mixed> scalar values (Stringable and BackedEnum coerced)
     *
     * @throws ConfigurationException when a source cannot be read or a value cannot be a URL parameter
     */
    public static function extract(object $subject, array $params, ?string $locale = null, ?string $host = null): array
    {
        $out = [];
        foreach ($params as $name => $param) {
            // `self` is route model binding by definition: the object goes to the router bridge as it is.
            $out[$name] = self::isSelf($param) ? $subject : self::coerce($name, self::resolve($subject, $param, $locale, $host), $subject);
        }

        return $out;
    }

    private static function isSelf(string|ParamValue $param): bool
    {
        return $param === self::SELF || ($param instanceof Accessor && $param->path === self::SELF);
    }

    /**
     * @throws ConfigurationException
     */
    public static function resolve(object $subject, string|ParamValue $param, ?string $locale = null, ?string $host = null): mixed
    {
        return match (true) {
            \is_string($param) => self::read($subject, $param),
            $param instanceof Accessor => self::read($subject, $param->path),
            $param instanceof Value => $param->value,
            $param instanceof Formatted => self::format($subject, $param),
            $param instanceof Call => self::call($subject, $param, $locale, $host),
            $param instanceof Equals => self::equals(self::read($subject, $param->path), $param->value),
            default => throw new ConfigurationException(\sprintf('Unsupported param source %s on %s: a param is an accessor string ("slug", "author.slug") or one of Accessor, Value, Formatted, Call, Equals from IndexNowKit\\Attribute\\Param.', get_debug_type($param), $subject::class)),
        };
    }

    /**
     * Accessor DSL: "self" | dotted path | registered {@see SubjectReaderInterface} | method | get/is/has-prefixed
     * method | property (also private).
     *
     * @throws ConfigurationException when nothing matches
     */
    public static function read(object $subject, string $accessor): mixed
    {
        if ($accessor === self::SELF) {
            return $subject;
        }
        if (str_contains($accessor, '.')) {
            $value = $subject;
            foreach (explode('.', $accessor) as $segment) {
                if (!\is_object($value)) {
                    throw new ConfigurationException(\sprintf('Cannot read "%s" on %s: "%s" is not an object.', $accessor, $subject::class, $segment));
                }
                $value = self::read($value, $segment);
            }

            return $value;
        }
        foreach (self::$readers as $reader) {
            if ($reader->has($subject, $accessor)) {
                return $reader->read($subject, $accessor);
            }
        }
        $ucfirst = ucfirst($accessor);
        foreach ([$accessor, 'get' . $ucfirst, 'is' . $ucfirst, 'has' . $ucfirst] as $method) {
            if (method_exists($subject, $method)) {
                return $subject->$method(); // @phpstan-ignore method.dynamicName
            }
        }
        if (property_exists($subject, $accessor)) {
            return (fn() => $this->$accessor)->call($subject); // @phpstan-ignore property.dynamicName
        }

        throw new ConfigurationException(\sprintf('Cannot read "%s" on %s: no method %s(), %s(), %s() or %s(), no property "%s"%s. Fix the accessor, or register a SubjectReaderInterface for this kind of object.', $accessor, $subject::class, $accessor, 'get' . $ucfirst, 'is' . $ucfirst, 'has' . $ucfirst, $accessor, self::$readers === [] ? '' : \sprintf(', and none of the registered readers (%s) claims it', implode(', ', array_keys(self::$readers)))));
    }

    /**
     * Evaluate a `when` condition: accessor string (truthy), ParamValue (truthy, {@see Equals} for comparisons)
     * or a closure `fn(object): bool` (runtime-registered rules only).
     *
     * @throws ConfigurationException
     */
    public static function condition(object $subject, string|ParamValue|Closure $when): bool
    {
        if ($when instanceof Closure) {
            return (bool) $when($subject);
        }

        return (bool) self::resolve($subject, $when);
    }

    private static function equals(mixed $actual, mixed $expected): bool
    {
        if ($actual instanceof BackedEnum) {
            $actual = $expected instanceof BackedEnum ? $actual : $actual->value;
        }
        if ($expected instanceof BackedEnum && !$actual instanceof BackedEnum) {
            $expected = $expected->value;
        }

        return $actual === $expected;
    }

    /**
     * @throws ConfigurationException
     */
    private static function format(object $subject, Formatted $param): string
    {
        $value = self::read($subject, $param->path);
        if (!$value instanceof DateTimeInterface) {
            throw new ConfigurationException(\sprintf('new Formatted("%s", "%s") on %s needs a DateTimeInterface, got %s.', $param->path, $param->format, $subject::class, get_debug_type($value)));
        }

        return $value->format($param->format);
    }

    /**
     * @throws ConfigurationException
     */
    private static function call(object $subject, Call $param, ?string $locale, ?string $host): mixed
    {
        if (!method_exists($subject, $param->method)) {
            throw new ConfigurationException(\sprintf('Cannot call "%s" on %s: no such method.', $param->method, $subject::class));
        }
        $args = array_map(static fn(mixed $arg): mixed => match ($arg) {
            Placeholder::Locale => $locale,
            Placeholder::Host => $host,
            default => $arg,
        }, $param->args);

        return $subject->{$param->method}(...$args); // @phpstan-ignore method.dynamicName
    }

    /**
     * Route parameters must be scalar. Stringable and BackedEnum values are accepted so value objects work
     * unchanged; a date without new Formatted(...) is the one mistake worth naming explicitly. An object a
     * registered reader supports (an Eloquent model, which is Stringable through its JSON form) stays an object:
     * a model in a route parameter means route model binding.
     *
     * @throws ConfigurationException
     */
    private static function coerce(string $name, mixed $value, object $subject): mixed
    {
        if ($value === null || \is_scalar($value)) {
            return $value;
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof DateTimeInterface) {
            throw new ConfigurationException(\sprintf('Param "%s" of %s is a %s; wrap it in new Formatted("...", "Y-m-d").', $name, $subject::class, $value::class));
        }
        if (\is_object($value)) {
            foreach (self::$readers as $reader) {
                if ($reader->supports($value)) {
                    return $value;
                }
            }
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if (\is_object($value)) {
            return $value; // route model binding (params: ['post' => 'self']); the router bridge decides
        }

        throw new ConfigurationException(\sprintf('Param "%s" of %s is a %s and cannot be a URL parameter.', $name, $subject::class, get_debug_type($value)));
    }
}
