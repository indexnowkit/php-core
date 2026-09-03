<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\Spool;
use IndexNowKit\Sitemap\SpoolMode;

/**
 * Where the sitemap command keeps documents while parsing: a read-only container without a writable temp dir is the
 * kind of thing that otherwise only shows up on the first scheduled run. Built from the adapter's raw `sitemap`
 * config block (`enabled`, `spool`, `spool_dir`, `max_bytes`); disabled sitemaps print nothing.
 */
final class SitemapSpoolCheck implements CheckInterface
{
    /**
     * @param array<string, mixed> $sitemap the raw `sitemap` config block
     */
    public function __construct(private readonly array $sitemap) {}

    public function check(CheckReport $report): void
    {
        $sitemap = $this->sitemap;
        if (($sitemap['enabled'] ?? true) === false) {
            return;
        }
        $mode = SpoolMode::tryFrom(\is_string($sitemap['spool'] ?? null) ? $sitemap['spool'] : 'auto') ?? SpoolMode::Auto;
        $dir = \is_string($sitemap['spool_dir'] ?? null) && $sitemap['spool_dir'] !== '' ? $sitemap['spool_dir'] : null;
        if ($mode === SpoolMode::Memory) {
            $report->ok(\sprintf('sitemap: documents are spooled in memory (sitemap.spool: memory, at most %s per document)', self::bytes($sitemap['max_bytes'] ?? null)));

            return;
        }
        $problem = Spool::probeDisk($dir);
        if ($problem === null) {
            $report->ok(\sprintf('sitemap: documents are spooled to temp files in %s', $dir ?? sys_get_temp_dir()));
        } elseif ($mode === SpoolMode::Disk) {
            $report->error(\sprintf('sitemap: %s and sitemap.spool is "disk": the sitemap command will fail. Mount a writable volume, set sitemap.spool_dir, or use "auto" / "memory".', $problem));
        } else {
            $report->warning(\sprintf('sitemap: %s: the sitemap command will spool documents in memory (at most %s each). Mount a writable temp dir or set sitemap.spool_dir.', $problem, self::bytes($sitemap['max_bytes'] ?? null)));
        }
    }

    private static function bytes(mixed $value): string
    {
        $bytes = \is_int($value) ? $value : SitemapReader::MAX_XML_BYTES;

        return $bytes >= 1_048_576 ? \sprintf('%d MiB', intdiv($bytes, 1_048_576)) : \sprintf('%d KiB', intdiv($bytes, 1024));
    }
}
