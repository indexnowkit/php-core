<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use BackedEnum;
use DateTimeInterface;
use IndexNowKit\Attribute\Param\Accessor;
use IndexNowKit\Attribute\Param\Call;
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

    private function __construct() {}

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
            $out[$name] = self::coerce($name, self::resolve($subject, $param, $locale, $host), $subject);
        }

        return $out;
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
            default => throw new ConfigurationException(\sprintf('Unsupported param source %s on %s.', get_debug_type($param), $subject::class)),
        };
    }

    /**
     * Accessor DSL: "self" | dotted path | method | get/is/has-prefixed method | property (also private).
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
        $ucfirst = ucfirst($accessor);
        foreach ([$accessor, 'get' . $ucfirst, 'is' . $ucfirst, 'has' . $ucfirst] as $method) {
            if (method_exists($subject, $method)) {
                return $subject->$method(); // @phpstan-ignore method.dynamicName
            }
        }
        if (property_exists($subject, $accessor)) {
            return (fn() => $this->$accessor)->call($subject); // @phpstan-ignore property.dynamicName
        }

        throw new ConfigurationException(\sprintf('Cannot read "%s" on %s: no property, getter or method found.', $accessor, $subject::class));
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
     * unchanged; a date without new Formatted(...) is the one mistake worth naming explicitly.
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
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if (\is_object($value)) {
            return $value; // route model binding (params: ['post' => 'self']); the router bridge decides
        }

        throw new ConfigurationException(\sprintf('Param "%s" of %s is a %s and cannot be a URL parameter.', $name, $subject::class, get_debug_type($value)));
    }
}
