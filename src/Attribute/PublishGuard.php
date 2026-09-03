<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use IndexNowKit\Exception\ConfigurationException;

/**
 * Evaluates the attribute's "when" condition on the current object state.
 *
 * @internal use GuardedUrlResolver
 */
final class PublishGuard
{
    /**
     * @throws ConfigurationException when the `when` accessor cannot be read
     */
    public static function isPublished(object $subject, IndexNow $attribute): bool
    {
        if ($attribute->when === null) {
            return true;
        }

        return (bool) ParamExtractor::read($subject, $attribute->when);
    }
}
