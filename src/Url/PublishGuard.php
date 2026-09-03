<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Attribute\IndexNow;

/**
 * Evaluates the attribute's "when" condition on the current object state.
 */
final class PublishGuard
{
    public static function isPublished(object $subject, IndexNow $attribute): bool
    {
        if ($attribute->when === null) {
            return true;
        }

        return (bool) ParamExtractor::read($subject, $attribute->when);
    }
}
