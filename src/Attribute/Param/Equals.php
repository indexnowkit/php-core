<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

use BackedEnum;
use IndexNowKit\Attribute\ParamExtractor;

/**
 * Condition for `when`: the value behind an accessor equals a constant (loosely for BackedEnum: the enum or its
 * backing value). For models whose "published" state is a string or an enum rather than a bool:
 *
 * #[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: new Equals('status', 'published'))]
 *
 * A condition, not a value source: `Equals` in `params` is a type error (`ParamExtractor` names the fix).
 *
 * The core evaluates it through the graph's `ParamExtractor` (`heldFor()` of the value read for `field()`), so the
 * accessor goes through the adapter's readers (Eloquent attributes); {@see evaluate()} alone is the plain DSL.
 */
final readonly class Equals implements FieldCondition
{
    public function __construct(public string $path, public mixed $value) {}

    public function evaluate(object $subject): bool
    {
        return $this->heldFor((new ParamExtractor())->read($subject, $this->path));
    }

    public function field(): string
    {
        return $this->path;
    }

    public function heldFor(mixed $oldValue): bool
    {
        $expected = $this->value;
        if ($oldValue instanceof BackedEnum) {
            $oldValue = $expected instanceof BackedEnum ? $oldValue : $oldValue->value;
        }
        if ($expected instanceof BackedEnum && !$oldValue instanceof BackedEnum) {
            $expected = $expected->value;
        }

        return $oldValue === $expected;
    }
}
