<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use IndexNowKit\Exception\ConfigurationException;
use ReflectionClass;

/**
 * Compiles #[IndexNow] / #[IndexNowDefaults] / #[IndexNowUrl] of a class (and its parents) into a RuleSet,
 * cached per class for the process lifetime.
 *
 * @throws ConfigurationException (from rules()) for an unknown class or a malformed attribute
 */
final class AttributeReader implements AttributeReaderInterface
{
    /** @var array<class-string, RuleSet> */
    private array $cache = [];

    public function rules(string|object $classOrObject): RuleSet
    {
        $class = \is_object($classOrObject) ? $classOrObject::class : $classOrObject;

        if (!class_exists($class)) {
            throw new ConfigurationException(\sprintf('Cannot read #[IndexNow] rules: class %s does not exist.', $class));
        }

        return $this->cache[$class] ??= RuleCompiler::compile(new ReflectionClass($class));
    }
}
