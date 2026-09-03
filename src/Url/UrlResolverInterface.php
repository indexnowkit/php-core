<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Event;

/**
 * Turns a domain object into zero or more public URLs. The default implementation (AttributeUrlResolver)
 * follows the object's #[IndexNow] rules; custom ones are referenced from #[IndexNow(resolver: ...)] or
 * replace the default entirely.
 */
interface UrlResolverInterface
{
    /**
     * @return iterable<string> absolute or base_url-relative URLs; empty when the object has no public page
     *
     * @throws \IndexNowKit\Exception\ConfigurationException when the object's declaration cannot be resolved (missing
     *                                                       accessor, route or service); ORM hooks go through
     *                                                       GuardedUrlResolver, which logs instead of throwing
     */
    public function resolve(object $subject, Event $event): iterable;
}
