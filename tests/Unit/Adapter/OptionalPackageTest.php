<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Adapter;

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The one predicate for an optional package of the family and the three texts that go with it (spec 17 §4.3).
 */
final class OptionalPackageTest extends TestCase
{
    #[TestDox('installed() is class_exists() of the marker unless overridden')]
    public function testInstalled(): void
    {
        self::assertTrue((new OptionalPackage('indexnowkit/core', OptionalPackage::class, 'core'))->installed(), 'a class of this package exists');
        self::assertFalse((new OptionalPackage('indexnowkit/nope', 'IndexNowKit\\Nope\\Reader', 'nope'))->installed(), 'an absent class: not installed, and ::class on it was safe');
        self::assertFalse((new OptionalPackage('indexnowkit/core', OptionalPackage::class, 'core', installed: false))->installed(), 'the override wins over the marker');
        self::assertTrue((new OptionalPackage('indexnowkit/nope', 'IndexNowKit\\Nope\\Reader', 'nope', installed: true))->installed());
    }

    #[TestDox('the install line names the package twice: the fact and the command')]
    public function testNotInstalledMessage(): void
    {
        $package = new OptionalPackage('indexnowkit/sitemap', 'IndexNowKit\\Sitemap\\SitemapReader', 'sitemap', installed: false);

        self::assertSame('indexnowkit/sitemap is not installed: composer require indexnowkit/sitemap', $package->notInstalledMessage());
        self::assertSame('indexnowkit/sitemap', $package->package);
        self::assertSame('sitemap', $package->feature);
    }

    #[TestDox('the check line is ok without a block or with the shipped defaults, a warning when a configured block is ignored')]
    public function testCheckLineAndLevel(): void
    {
        $package = new OptionalPackage('indexnowkit/sitemap', 'IndexNowKit\\Sitemap\\SitemapReader', 'sitemap', installed: false);
        $defaults = ['enabled' => true, 'spool' => 'auto', 'url' => null];
        $plain = 'sitemap: not installed (composer require indexnowkit/sitemap)';
        $ignored = 'sitemap: not installed, the sitemap block in the configuration is ignored (composer require indexnowkit/sitemap)';

        self::assertSame($plain, $package->checkLine([]));
        self::assertSame($plain, $package->checkLine([], $defaults));
        self::assertSame($plain, $package->checkLine(['url' => null, 'spool' => 'auto', 'enabled' => true], $defaults), 'the same block in another order is the defaults');
        self::assertSame($ignored, $package->checkLine(['spool' => 'disk'], $defaults));
        self::assertSame($ignored, $package->checkLine(['spool' => 'disk']), 'no defaults given: any block counts as configured');
        self::assertSame($ignored, $package->checkLine(['enabled' => true, 'spool' => 'auto', 'url' => null, 'spol' => 'disk'], $defaults), 'a typo is a configured block too');

        self::assertSame(CheckLevel::Ok, $package->checkLevel([], $defaults));
        self::assertSame(CheckLevel::Ok, $package->checkLevel($defaults, $defaults));
        self::assertSame(CheckLevel::Warning, $package->checkLevel(['spool' => 'disk'], $defaults));

        $report = new CheckReport();
        $package->check(['spool' => 'disk'], $defaults)->check($report);
        $package->check([], $defaults)->check($report);
        self::assertSame([$ignored, $plain], array_map(static fn($item): string => $item->message, $report->items()));
        self::assertSame([CheckLevel::Warning, CheckLevel::Ok], array_map(static fn($item): CheckLevel => $item->level, $report->items()));
    }

    #[TestDox('nested blocks are compared by content, not by key order')]
    public function testNestedDefaults(): void
    {
        $package = new OptionalPackage('indexnowkit/verify', 'IndexNowKit\\Verify\\Nope', 'verify', installed: false);
        $defaults = ['enabled' => false, 'policy' => ['redirect' => 'skip', 'origin_error' => 'skip']];

        self::assertSame(CheckLevel::Ok, $package->checkLevel(['policy' => ['origin_error' => 'skip', 'redirect' => 'skip'], 'enabled' => false], $defaults));
        self::assertSame(CheckLevel::Warning, $package->checkLevel(['policy' => ['origin_error' => 'send', 'redirect' => 'skip'], 'enabled' => false], $defaults));
    }
}
