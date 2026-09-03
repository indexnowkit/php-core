<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use IndexNowKit\Event;

/**
 * Turns an ORM update into the lifecycle event the attribute cares about, identically in every adapter:
 * `when` flipping true -> false is a deletion (engines recrawl the 404), false -> true a creation, otherwise an
 * update filtered by `fields`. Null means "nothing to submit".
 */
final class ChangeClassifier
{
    private function __construct() {}

    /**
     * @param list<string>     $changedFields property names changed in this update
     * @param array{mixed, mixed}|null $whenChange   [old, new] values of the `when` property if it changed, null otherwise
     */
    public static function classify(IndexNow $attribute, array $changedFields, ?array $whenChange): ?Event
    {
        if ($attribute->when !== null && $whenChange !== null) {
            [$old, $new] = $whenChange;
            if ((bool) $old && !(bool) $new) {
                return $attribute->listensTo(Event::Deleted) ? Event::Deleted : null;
            }
            if (!(bool) $old && (bool) $new) {
                return $attribute->listensTo(Event::Created) ? Event::Created : null;
            }
        }
        if (!$attribute->listensTo(Event::Updated) || !$attribute->caresAbout($changedFields)) {
            return null;
        }

        return Event::Updated;
    }
}
