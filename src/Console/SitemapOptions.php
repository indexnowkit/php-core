<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

/**
 * Raw command-line input of `sitemap`, before validation.
 */
final class SitemapOptions
{
    /**
     * @param string|null $sitemap           sitemap URL or local file; null = the configured default, else <base_url>/sitemap.xml
     * @param string|null $changedSince      only URLs whose <lastmod> is newer: "1 day", "2026-09-01"
     * @param bool        $allowForeignHosts follow nested sitemaps hosted on another origin for this run
     * @param bool        $dryRun            list the URLs without submitting
     */
    public function __construct(
        public readonly ?string $sitemap = null,
        public readonly ?string $changedSince = null,
        public readonly bool $allowForeignHosts = false,
        public readonly bool $force = false,
        public readonly bool $dryRun = false,
        public readonly bool $json = false,
    ) {}
}
