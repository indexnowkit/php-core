<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap;

use DateTimeImmutable;

/**
 * One <url> of a sitemap.
 */
final readonly class SitemapEntry
{
    public function __construct(public string $url, public ?DateTimeImmutable $lastmod) {}
}
