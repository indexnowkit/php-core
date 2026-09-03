<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

/**
 * Turns a domain object into zero or more public URLs. Implemented per framework (router-aware).
 */
interface UrlResolverInterface
{
    /**
     * @return iterable<string> absolute or base_url-relative URLs; empty when the object has no public page
     */
    public function resolve(object $subject, Event $event): iterable;
}
