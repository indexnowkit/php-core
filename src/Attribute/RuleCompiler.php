<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Compiles #[IndexNowDefaults] + #[IndexNow] + #[IndexNowUrl] of a class and its parents into a RuleSet.
 *
 * Hierarchy is walked root -> leaf. Defaults merge field by field, the nearest declaration wins. Rules
 * accumulate; a rule whose name repeats an ancestor's replaces it, which is how a subclass overrides one page
 * without restating the others. Interfaces and traits are not scanned (PHP does not inherit class attributes
 * through them; Doctrine mapping behaves the same way).
 */
final class RuleCompiler
{
    private const ALL_EVENTS = [Event::Created, Event::Updated, Event::Deleted];

    private function __construct() {}

    /**
     * @param ReflectionClass<object> $class
     *
     * @throws ConfigurationException on a malformed attribute
     */
    public static function compile(ReflectionClass $class): RuleSet
    {
        /** @var list<ReflectionClass<object>> $chain */
        $chain = [];
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            $chain[] = $current;
        }
        $chain = array_reverse($chain);

        $defaults = new IndexNowDefaults();
        foreach ($chain as $current) {
            foreach ($current->getAttributes(IndexNowDefaults::class) as $attribute) {
                $declared = $attribute->newInstance();
                $defaults = new IndexNowDefaults(
                    when: $declared->when ?? $defaults->when,
                    whenFields: $declared->when !== null ? $declared->whenFields : [...$defaults->whenFields, ...$declared->whenFields],
                    fields: $declared->fields ?? $defaults->fields,
                    events: $declared->events ?? $defaults->events,
                    locales: $declared->locales ?? $defaults->locales,
                );
            }
        }

        /** @var array<string, UrlRule> $rules */
        $rules = [];
        foreach ($chain as $current) {
            $taken = [];
            foreach ($current->getAttributes(IndexNow::class) as $attribute) {
                $rule = self::fromClassAttribute($attribute->newInstance(), $defaults, $taken);
                $taken[$rule->name] = true;
                $rules[$rule->name] = $rule;
            }
            foreach ($current->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $current->getName()) {
                    continue;
                }
                foreach ($method->getAttributes(IndexNowUrl::class) as $attribute) {
                    $rule = self::fromMethodAttribute($attribute->newInstance(), $method, $defaults);
                    if (isset($taken[$rule->name])) {
                        throw new ConfigurationException(\sprintf('%s declares rule "%s" twice (#[IndexNow(url: ...)] on the class and #[IndexNowUrl] on the method); keep one or give it a name.', $current->getName(), $rule->name));
                    }
                    $taken[$rule->name] = true;
                    $rules[$rule->name] = $rule;
                }
            }
        }

        return new RuleSet($class->getName(), array_values($rules));
    }

    /**
     * Compile attribute instances built in code (runtime registration, no reflection).
     *
     * @param class-string   $class
     * @param list<IndexNow> $attributes
     *
     * @throws ConfigurationException on a malformed attribute
     */
    public static function fromAttributes(string $class, array $attributes, ?IndexNowDefaults $defaults = null): RuleSet
    {
        $defaults ??= new IndexNowDefaults();
        $rules = [];
        $taken = [];
        foreach ($attributes as $attribute) {
            $rule = self::fromClassAttribute($attribute, $defaults, $taken);
            $taken[$rule->name] = true;
            $rules[$rule->name] = $rule;
        }

        return new RuleSet($class, array_values($rules));
    }

    /**
     * @param array<string, true> $taken names already used in this class
     */
    private static function fromClassAttribute(IndexNow $a, IndexNowDefaults $defaults, array $taken): UrlRule
    {
        $source = match (true) {
            $a->route !== null => RuleSource::Route,
            $a->resolver !== null => RuleSource::Resolver,
            $a->via !== null => RuleSource::Via,
            $a->url !== null => RuleSource::Url,
            default => RuleSource::Urls,
        };

        return new UrlRule(
            name: $a->name ?? self::defaultName($a, $source, $taken),
            source: $source,
            route: $a->route,
            params: $a->params,
            resolver: $a->resolver,
            via: $a->via,
            url: $a->url,
            urls: $a->urls,
            when: self::when($defaults, $a->when),
            whenFields: [...$defaults->whenFields, ...$a->whenFields],
            fields: $a->fields ?? $defaults->fields ?? [],
            events: $a->events ?? $defaults->events ?? self::ALL_EVENTS,
            locales: $a->locales ?? $defaults->locales ?? 'current',
            host: $a->host,
        );
    }

    private static function fromMethodAttribute(IndexNowUrl $a, ReflectionMethod $method, IndexNowDefaults $defaults): UrlRule
    {
        if ($method->getNumberOfRequiredParameters() > 0) {
            throw new ConfigurationException(\sprintf('#[IndexNowUrl] on %s::%s(): the method must not require arguments.', $method->getDeclaringClass()->getName(), $method->getName()));
        }

        return new UrlRule(
            name: $a->name ?? 'url:' . $method->getName(),
            source: RuleSource::Url,
            url: $method->getName(),
            when: self::when($defaults, $a->when),
            whenFields: [...$defaults->whenFields, ...$a->whenFields],
            fields: $a->fields ?? $defaults->fields ?? [],
            events: $a->events ?? $defaults->events ?? self::ALL_EVENTS,
            locales: $defaults->locales ?? 'current',
        );
    }

    /**
     * @return list<string>
     */
    private static function when(IndexNowDefaults $defaults, ?string $own): array
    {
        $when = $defaults->when !== null ? [$defaults->when] : [];
        if ($own !== null && !\in_array($own, $when, true)) {
            $when[] = $own;
        }

        return $when;
    }

    /**
     * @param array<string, true> $taken
     */
    private static function defaultName(IndexNow $a, RuleSource $source, array $taken): string
    {
        $base = match ($source) {
            RuleSource::Route => (string) $a->route,
            RuleSource::Resolver => 'resolver:' . self::shortName((string) $a->resolver),
            RuleSource::Via => 'via:' . (string) $a->via,
            RuleSource::Url => 'url:' . (string) $a->url,
            RuleSource::Urls => 'urls:' . implode(',', \array_slice($a->urls, 0, 2)),
        };
        if (!isset($taken[$base])) {
            return $base;
        }
        $i = 2;
        while (isset($taken[$base . '#' . $i])) {
            ++$i;
        }

        return $base . '#' . $i;
    }

    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
