<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\SitemapSpoolCheck;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class SitemapSpoolCheckTest extends TestCase
{
    #[TestDox('disabled sitemaps print nothing; memory, a writable and an unwritable spool dir under auto and disk')]
    public function testLevels(): void
    {
        self::assertSame([], self::levels(new SitemapSpoolCheck(['enabled' => false])));

        $check = new SitemapSpoolCheck(['spool' => 'memory', 'max_bytes' => 1024]);
        self::assertSame([CheckLevel::Ok], self::levels($check));
        self::assertStringContainsString('in memory (sitemap.spool: memory, at most 1 KiB', self::messages($check)[0]);

        $check = new SitemapSpoolCheck(['spool' => 'auto', 'spool_dir' => sys_get_temp_dir()]);
        self::assertSame([CheckLevel::Ok], self::levels($check));
        self::assertStringContainsString('spooled to temp files in ' . sys_get_temp_dir(), self::messages($check)[0]);

        $check = new SitemapSpoolCheck(['spool' => 'auto', 'spool_dir' => '/nonexistent/indexnow']);
        self::assertSame([CheckLevel::Warning], self::levels($check));
        self::assertStringContainsString('at most 50 MiB each', self::messages($check)[0]);

        $check = new SitemapSpoolCheck(['spool' => 'disk', 'spool_dir' => '/nonexistent/indexnow']);
        self::assertSame([CheckLevel::Error], self::levels($check));
        self::assertStringContainsString('does not exist', self::messages($check)[0]);

        self::assertSame([CheckLevel::Ok], self::levels(new SitemapSpoolCheck([])), 'defaults: auto mode, system temp dir');
    }

    /**
     * @return list<CheckLevel>
     */
    private static function levels(SitemapSpoolCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): CheckLevel => $item->level, $report->items());
    }

    /**
     * @return list<string>
     */
    private static function messages(SitemapSpoolCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): string => $item->message, $report->items());
    }
}
