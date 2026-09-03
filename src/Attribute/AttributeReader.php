<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use ReflectionClass;

/**
 * Reads #[IndexNow] from a class or its parents. Caches per class for the process lifetime.
 */
final class AttributeReader implements AttributeReaderInterface
{
    /** @var array<class-string, IndexNow|null> */
    private array $cache = [];

    public function read(string|object $classOrObject): ?IndexNow
    {
        $class = \is_object($classOrObject) ? $classOrObject::class : $classOrObject;
        if (\array_key_exists($class, $this->cache)) {
            return $this->cache[$class];
        }

        return $this->cache[$class] = self::readUncached(new ReflectionClass($class));
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private static function readUncached(ReflectionClass $reflection): ?IndexNow
    {
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            $attributes = $current->getAttributes(IndexNow::class);
            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
        }

        return null;
    }
}
