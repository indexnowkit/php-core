<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use IndexNowKit\Exception\ConfigurationException;

/**
 * Rules registered at runtime, for adapters whose models cannot carry attributes (CMS post types, closure
 * APIs such as `IndexNow::observe(Post::class, ...)`), on top of an inner reader (attributes by default).
 *
 * $registry->register(Post::class, [new IndexNow(route: 'posts.show', params: ['post' => 'self'])], new IndexNowDefaults(when: 'isPublished'));
 * $registry->registerFor(WP_Post::class, fn(WP_Post $post): ?RuleSet => ...);   // decided per object
 *
 * Registered rules replace whatever the inner reader would return for that class; subclasses inherit them.
 */
final class RuleRegistry implements AttributeReaderInterface
{
    /** @var array<class-string, RuleSet> */
    private array $static = [];

    /** @var array<class-string, callable(object): ?RuleSet> */
    private array $factories = [];

    public function __construct(private readonly AttributeReaderInterface $inner = new AttributeReader()) {}

    /**
     * @param class-string    $class
     * @param list<IndexNow>  $rules
     *
     * @throws ConfigurationException on an invalid rule
     */
    public function register(string $class, array $rules, ?IndexNowDefaults $defaults = null): void
    {
        $this->static[$class] = RuleCompiler::fromAttributes($class, $rules, $defaults);
    }

    /**
     * @param class-string           $class
     * @param callable(object): ?RuleSet $factory null = fall through to the inner reader
     */
    public function registerFor(string $class, callable $factory): void
    {
        $this->factories[$class] = $factory;
    }

    public function rules(string|object $classOrObject): RuleSet
    {
        $class = \is_object($classOrObject) ? $classOrObject::class : $classOrObject;
        if (\is_object($classOrObject)) {
            foreach ($this->factories as $registered => $factory) {
                if ($classOrObject instanceof $registered) {
                    $rules = $factory($classOrObject);
                    if ($rules !== null) {
                        return $rules;
                    }
                }
            }
        }
        foreach ($this->static as $registered => $rules) {
            if ($class === $registered || is_subclass_of($class, $registered)) {
                return $rules;
            }
        }

        return $this->inner->rules($classOrObject);
    }
}
