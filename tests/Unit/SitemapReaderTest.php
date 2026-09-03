<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use DateTimeImmutable;
use IndexNowKit\Http\Response;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class SitemapReaderTest extends TestCase
{
    private const URLSET = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/a</loc><lastmod>2026-09-01T10:00:00+00:00</lastmod></url><url><loc>https://www.example.com/b</loc><lastmod>2026-01-01</lastmod></url><url><loc>https://www.example.com/c</loc></url></urlset>';

    public function testParsesUrlsetWithLastmodFilter(): void
    {
        $reader = new SitemapReader(new FakeTransport());
        $all = iterator_to_array($reader->parse(self::URLSET), false);
        self::assertCount(3, $all);
        self::assertSame('https://www.example.com/a', $all[0]->url);
        self::assertNotNull($all[1]->lastmod);
        self::assertNull($all[2]->lastmod);

        $recent = iterator_to_array($reader->parse(self::URLSET, '', new DateTimeImmutable('2026-08-01')), false);
        self::assertCount(1, $recent);
    }

    public function testFollowsSitemapIndexAndGzip(): void
    {
        $t = new FakeTransport();
        $index = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://www.example.com/s1.xml.gz</loc></sitemap></sitemapindex>';
        $t->onGet('https://www.example.com/sitemap.xml', new Response(200, $index));
        $t->onGet('https://www.example.com/s1.xml.gz', new Response(200, (string) gzencode(self::URLSET)));

        $entries = iterator_to_array((new SitemapReader($t))->read('https://www.example.com/sitemap.xml'), false);
        self::assertCount(3, $entries);
    }
}
