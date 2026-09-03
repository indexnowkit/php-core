<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use ReflectionClass;

/**
 * Compiles #[IndexNow] / #[IndexNowDefaults] / #[IndexNowUrl] of a class (and its parents) into a RuleSet,
 * cached per class for the process lifetime.
 */
final class AttributeReader implements AttributeReaderInterface
{
    /** @var array<class-string, RuleSet> */
    private array $cache = [];

    public function rules(string|object $classOrObject): RuleSet
    {
        $class = \is_object($classOrObject) ? $classOrObject::class : $classOrObject;

        return $this->cache[$class] ??= RuleCompiler::compile(new ReflectionClass($class));
    }
}
