<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

/**
 * Finds the #[IndexNow] attribute of a class (or its parents). Adapters may decorate it with their own cache.
 */
interface AttributeReaderInterface
{
    /**
     * @param class-string|object $classOrObject
     */
    public function read(string | object $classOrObject) : ? IndexNow;
}
