<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\StaticCheck;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class StaticCheckTest extends TestCase
{
    #[TestDox('prints its line at the given level, one item per check')]
    public function testLevels(): void
    {
        foreach (CheckLevel::cases() as $level) {
            $report = new CheckReport();
            (new StaticCheck($level, 'sitemap: not installed (composer require indexnowkit/sitemap)'))->check($report);

            self::assertCount(1, $report->items());
            self::assertSame($level, $report->items()[0]->level);
            self::assertSame('sitemap: not installed (composer require indexnowkit/sitemap)', $report->items()[0]->message);
            self::assertSame($level === CheckLevel::Error, $report->hasErrors());
            self::assertNull($report->items()[0]->code);
        }
    }

    public function testCodeIsPassedThrough(): void
    {
        $report = new CheckReport();
        (new StaticCheck(CheckLevel::Warning, 'sitemap: not installed, the sitemap block in the configuration is ignored', 'sitemap.installed'))->check($report);

        self::assertSame('sitemap.installed', $report->items()[0]->code);
        self::assertNull($report->items()[0]->host);
    }
}
