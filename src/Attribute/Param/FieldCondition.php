<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * A {@see Condition} that reads one field of the object, so `ChangeClassifier` can tell the old state from the ORM
 * change set: a `true → false` transition is a deletion of the rule's URLs, `false → true` a creation. A plain
 * `Condition` has no old value; the classifier then evaluates it on the current object only and cannot detect the
 * unpublish (see docs/attribute-reference.md).
 */
interface FieldCondition extends Condition
{
    /** The accessor the condition reads (`status`, `isPublished`); `UrlRule::fieldCandidates()` maps it to change-set keys. */
    public function field(): string;

    /** Whether the condition held for this old value of {@see field()}, as the change set stored it. */
    public function heldFor(mixed $oldValue): bool;
}
