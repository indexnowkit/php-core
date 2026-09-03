<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap;

use DateTimeImmutable;
use IndexNowKit\Http\Exception\TransportException;

/**
 * Where `sitemap` commands get their URLs from. {@see SitemapReader} is the shipped implementation (XML and text
 * sitemaps, sitemap indexes, gzip, HTTP or local files); an application replaces or decorates it to read from
 * another place, another format, or to filter what comes out.
 */
interface SitemapSourceInterface
{
    /**
     * @param string                 $sitemap      URL or path the command was given (or its configured default)
     * @param DateTimeImmutable|null $changedSince only entries known to have changed since then; entries whose
     *                                             modification time is unknown are skipped when it is set
     *
     * @return iterable<SitemapEntry> streamed: implementations should yield, not collect
     *
     * @throws TransportException when the sitemap itself cannot be read (a nested part is logged and skipped)
     */
    public function read(string $sitemap, ?DateTimeImmutable $changedSince = null): iterable;
}
