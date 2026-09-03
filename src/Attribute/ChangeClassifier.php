<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use BackedEnum;
use Closure;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\Param\ParamValue;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;

/**
 * Turns an ORM update into the lifecycle event one rule cares about, identically in every adapter.
 *
 * Visibility is evaluated per rule (class guard AND rule guard). true -> false is a deletion of that rule's
 * URLs only (engines recrawl the 404), false -> true a creation, otherwise an update filtered by `fields`.
 * Null means "nothing to submit for this rule".
 */
final class ChangeClassifier
{
    private function __construct() {}

    /**
     * @param list<string>                             $changedFields field names changed in this update
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet     field => [old, new] when the ORM provides it
     *
     * @throws ConfigurationException when a `when` accessor cannot be read
     */
    public static function classify(UrlRule $rule, object $subject, array $changedFields, array $changeSet = []): ?Event
    {
        $after = $rule->appliesTo($subject);
        $before = self::appliedBefore($rule, $subject, $changedFields, $changeSet, $after);

        if ($before && !$after) {
            return $rule->listensTo(Event::Deleted) ? Event::Deleted : null;
        }
        if (!$before && $after) {
            return $rule->listensTo(Event::Created) ? Event::Created : null;
        }
        if (!$after) {
            return null;
        }

        return $rule->listensTo(Event::Updated) && $rule->caresAbout($changedFields) ? Event::Updated : null;
    }

    /**
     * @param list<string> $changedFields
     */
    private static function touched(UrlRule $rule, string|ParamValue|Closure $condition, array $changedFields): bool
    {
        foreach ($changedFields as $field) {
            if ($rule->conditionDependsOn($condition, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a condition held for the old value of its field: truthiness for accessors, comparison for Equals.
     */
    private static function heldBefore(string|ParamValue|Closure $condition, mixed $oldValue): bool
    {
        if ($condition instanceof Equals) {
            $expected = $condition->value instanceof BackedEnum ? $condition->value->value : $condition->value;
            $actual = $oldValue instanceof BackedEnum ? $oldValue->value : $oldValue;

            return $actual === $expected;
        }

        return (bool) $oldValue;
    }

    /**
     * Old-state visibility, best effort:
     *  - a `when` accessor whose backing field is in the change set (by name, or by convention `isPublished` ->
     *    `published`) is evaluated exactly from the old value;
     *  - an accessor with no change-set entry keeps its current value unless a field it depends on changed,
     *    in which case the outcome is assumed to have flipped: a false positive costs one request, a false
     *    negative leaves a dead page indexed.
     *
     * @param list<string>                             $changedFields
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private static function appliedBefore(UrlRule $rule, object $subject, array $changedFields, array $changeSet, bool $after): bool
    {
        if ($rule->when === []) {
            return true;
        }
        $unknown = false;
        foreach ($rule->when as $condition) {
            $accessor = UrlRule::accessorOf($condition);
            $key = null;
            foreach ($accessor !== null ? UrlRule::fieldCandidates($accessor) : [] as $candidate) {
                if (\array_key_exists($candidate, $changeSet)) {
                    $key = $candidate;
                    break;
                }
            }
            if ($key !== null) {
                if (!self::heldBefore($condition, $changeSet[$key][0])) {
                    return false;
                }
                continue;
            }
            if (self::touched($rule, $condition, $changedFields)) {
                $unknown = true;
                continue;
            }
            if (!ParamExtractor::condition($subject, $condition)) {
                return false;
            }
        }

        return $unknown ? !$after : true;
    }
}
