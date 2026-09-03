<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;

/**
 * Placeholder when no resolver is configured: every entity resolution fails loudly (logged by GuardedUrlResolver).
 */
final class NullUrlResolver implements UrlResolverInterface
{
    public function resolve(object $subject, Event $event): iterable
    {
        throw new ConfigurationException(\sprintf('No UrlResolver configured, cannot resolve URLs for %s. Pass one to IndexNow::create() or use a framework adapter.', $subject::class));
    }
}
