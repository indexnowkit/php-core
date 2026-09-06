<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use BackedEnum;
use Closure;
use DateTimeInterface;
use IndexNowKit\Attribute\Param\Accessor;
use IndexNowKit\Attribute\Param\Call;
use IndexNowKit\Attribute\Param\Condition;
use IndexNowKit\Attribute\Param\FieldCondition;
use IndexNowKit\Attribute\Param\Formatted;
use IndexNowKit\Attribute\Param\ParamValue;
use IndexNowKit\Attribute\Param\Placeholder;
use IndexNowKit\Attribute\Param\Value;
use IndexNowKit\Exception\ConfigurationException;
use ReflectionProperty;
use Stringable;

/**
 * Reads rule params off an object. A plain string is the accessor DSL (property, getter, is/has method,
 * dotted path, "self"); a ParamValue is one of the typed sources. Extraction runs once per generated URL,
 * so Placeholder::Locale / Placeholder::Host resolve to the URL being built.
 *
 * The readers given to the constructor see into objects the DSL cannot (Eloquent attributes, CMS fields); they are
 * consulted for every single-segment accessor before the DSL. One instance per graph: `IndexNowKit::create(extractor:)`,
 * `Adapter\ServicesBuilder::paramExtractor()`, the adapters' containers (`ParamExtractor` binding / service) — the
 * resolver, the change handler and the `explain` command share it, so a reader added there is seen everywhere.
 * Without readers (`new ParamExtractor()`) it is the DSL alone, which is what plain PHP objects and Doctrine entities need.
 *
 * Public for adapters that evaluate `params` or `when` outside AttributeUrlResolver.
 */
final class ParamExtractor
{
    public const SELF = 'self';

    /** @var list<SubjectReaderInterface> */
    private readonly array $readers;

    /** Readers in the order they are asked; the first whose `has()` says yes reads the accessor. */
    public function __construct(SubjectReaderInterface ...$readers)
    {
        $this->readers = array_values($readers);
    }

    /**
     * The same, for a container that hands readers over as a collection (a Symfony tagged iterator).
     *
     * @param iterable<SubjectReaderInterface> $readers
     */
    public static function fromReaders(iterable $readers): self
    {
        return new self(...[...$readers]);
    }

    /** This extractor plus more readers, asked after the present ones. */
    public function with(SubjectReaderInterface ...$readers): self
    {
        return new self(...$this->readers, ...$readers);
    }

    /**
     * @return list<SubjectReaderInterface>
     */
    public function readers(): array
    {
        return $this->readers;
    }

    /**
     * @param array<string, string|ParamValue> $params routeParam => source
     *
     * @return array<string, mixed> scalar values (Stringable and BackedEnum coerced)
     *
     * @throws ConfigurationException when a source cannot be read or a value cannot be a URL parameter
     */
    public function extract(object $subject, array $params, ?string $locale = null, ?string $host = null): array
    {
        $out = [];
        foreach ($params as $name => $param) {
            if (!\is_string($param) && !$param instanceof ParamValue) { // the docblock promises one of the two; an attribute written by hand can still pass a Condition
                throw new ConfigurationException(\sprintf('Param "%s" of %s is a %s, which is not a value source: a param is an accessor string ("slug", "author.slug") or one of Accessor, Value, Formatted, Call from IndexNowKit\\Attribute\\Param. A condition such as Equals belongs to `when`, not `params`.', $name, $subject::class, get_debug_type($param)));
            }
            // `self` is route model binding by definition: the object goes to the router bridge as it is.
            $out[$name] = $this->isSelf($param) ? $subject : $this->coerce($name, $this->resolve($subject, $param, $locale, $host), $subject);
        }

        return $out;
    }

    private function isSelf(string|ParamValue $param): bool
    {
        return $param === self::SELF || ($param instanceof Accessor && $param->path === self::SELF);
    }

    /**
     * @throws ConfigurationException
     */
    public function resolve(object $subject, string|ParamValue $param, ?string $locale = null, ?string $host = null): mixed
    {
        return match (true) {
            \is_string($param) => $this->read($subject, $param),
            $param instanceof Accessor => $this->read($subject, $param->path),
            $param instanceof Value => $param->value,
            $param instanceof Formatted => $this->format($subject, $param),
            $param instanceof Call => $this->call($subject, $param, $locale, $host),
            default => throw new ConfigurationException(\sprintf('Unsupported param source %s on %s: a param is an accessor string ("slug", "author.slug") or one of Accessor, Value, Formatted, Call from IndexNowKit\\Attribute\\Param (a condition such as Equals belongs to `when`).', get_debug_type($param), $subject::class)),
        };
    }

    /**
     * Accessor DSL: "self" | dotted path | registered {@see SubjectReaderInterface} | method | get/is/has-prefixed
     * method | property (also private).
     *
     * @throws ConfigurationException when nothing matches
     */
    public function read(object $subject, string $accessor): mixed
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
                $value = $this->read($value, $segment);
            }

            return $value;
        }
        foreach ($this->readers as $reader) {
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
        if (property_exists($subject, $accessor) && (new ReflectionProperty($subject, $accessor))->isInitialized($subject)) {
            return (fn() => $this->$accessor)->call($subject); // @phpstan-ignore property.dynamicName
        }

        throw new ConfigurationException(\sprintf('Cannot read "%s" on %s: no method %s(), %s(), %s() or %s(), no initialized property "%s"%s. Fix the accessor, or give the ParamExtractor a SubjectReaderInterface for this kind of object.', $accessor, $subject::class, $accessor, 'get' . $ucfirst, 'is' . $ucfirst, 'has' . $ucfirst, $accessor, $this->readers === [] ? '' : \sprintf(', and none of the readers (%s) claims it', implode(', ', array_map(static fn(SubjectReaderInterface $r): string => $r::class, $this->readers)))));
    }

    /**
     * Evaluate a `when` condition: accessor string (truthy), a {@see Condition} (`Equals` and your own) or a closure
     * `fn(object): bool` (runtime-registered rules only). A {@see FieldCondition} is asked `heldFor()` the value this
     * extractor reads for its `field()`, so it sees Eloquent attributes through the readers; a plain Condition
     * evaluates the object itself.
     *
     * @throws ConfigurationException when an accessor cannot be read
     */
    public function condition(object $subject, string|Condition|Closure $when): bool
    {
        if ($when instanceof Closure) {
            return (bool) $when($subject);
        }
        if ($when instanceof FieldCondition) {
            return $when->heldFor($this->read($subject, $when->field()));
        }
        if ($when instanceof Condition) {
            return $when->evaluate($subject);
        }

        return (bool) $this->read($subject, $when);
    }

    /**
     * @throws ConfigurationException
     */
    private function format(object $subject, Formatted $param): string
    {
        $value = $this->read($subject, $param->path);
        if (!$value instanceof DateTimeInterface) {
            throw new ConfigurationException(\sprintf('new Formatted("%s", "%s") on %s needs a DateTimeInterface, got %s.', $param->path, $param->format, $subject::class, get_debug_type($value)));
        }

        return $value->format($param->format);
    }

    /**
     * @throws ConfigurationException
     */
    private function call(object $subject, Call $param, ?string $locale, ?string $host): mixed
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
    private function coerce(string $name, mixed $value, object $subject): mixed
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
            foreach ($this->readers as $reader) {
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
