<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * Explicit form of the accessor DSL (a plain string means the same): property, getter, is/has method,
 * dotted path through relations, or "self" for the object itself (route model binding).
 */
final readonly class Accessor implements ParamValue
{
    public function __construct(public string $path) {}
}
