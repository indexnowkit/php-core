<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * A `when` guard that reads one field of the object, so `ChangeClassifier` can tell the old state from the ORM
 * change set: a `true → false` transition is a deletion of the rule's URLs, `false → true` a creation. The core reads
 * the field through the graph's `ParamExtractor` (its readers see Eloquent attributes) and asks `heldFor()` — for the
 * current value and for the old one — so a field condition never reads the object itself; that is why it is not a
 * {@see Condition} (which has to, and so has no old value: the classifier then evaluates it on the current object
 * only and cannot detect the unpublish, see docs/attribute-reference.md). `Equals` is the shipped one.
 *
 * Implement tier (docs/bc.md): the core calls you; methods are not added in a minor. `heldFor()` must not throw for a
 * value the field can hold.
 */
interface FieldCondition
{
    /** The accessor the condition reads (`status`, `isPublished`); `UrlRule::fieldCandidates()` maps it to change-set keys. */
    public function field(): string;

    /** Whether the condition held for this old value of {@see field()}, as the change set stored it. */
    public function heldFor(mixed $oldValue): bool;
}
