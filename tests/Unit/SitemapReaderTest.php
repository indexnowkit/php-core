<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use DateTimeImmutable;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\Response;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Tests\Support\ArrayLogger;
use IndexNowKit\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;

final class SitemapReaderTest extends TestCase
{
    private const URLSET = '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://www.example.com/a</loc><lastmod>2026-09-01T10:00:00+00:00</lastmod></url><url><loc>https://www.example.com/b</loc><lastmod>2026-01-01</lastmod></url><url><loc>https://www.example.com/c</loc></url></urlset>';

    private const NS = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';

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

    public function testNestedSitemapOnAnotherHostIsSkippedWithWarning(): void
    {
        $logger = new ArrayLogger();
        $t = new FakeTransport();
        $index = '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://other.example.com/s1.xml</loc></sitemap></sitemapindex>';
        $t->onGet('https://www.example.com/sitemap.xml', new Response(200, $index));

        $entries = iterator_to_array((new SitemapReader($t, logger: $logger))->read('https://www.example.com/sitemap.xml'), false);

        self::assertSame([], $entries);
        self::assertCount(1, $logger->messages('warning'));
        self::assertStringContainsString('not on the host', implode("\n", $logger->messages('warning')));
    }

    public function testNestedSitemapOnAnotherPortOrSchemeIsSkipped(): void
    {
        $logger = new ArrayLogger();
        $t = new FakeTransport();
        $index = '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://www.example.com:9200/s1.xml</loc></sitemap><sitemap><loc>http://www.example.com/s2.xml</loc></sitemap></sitemapindex>';
        $t->onGet('https://www.example.com/sitemap.xml', new Response(200, $index));

        $entries = iterator_to_array((new SitemapReader($t, logger: $logger))->read('https://www.example.com/sitemap.xml'), false);

        self::assertSame([], $entries);
        self::assertSame(['https://www.example.com/sitemap.xml'], $t->gets, 'neither the other port nor the http downgrade is fetched');
        self::assertCount(2, $logger->messages('warning'));
    }

    public function testDocumentLargerThanTheLimitIsRejected(): void
    {
        $reader = new SitemapReader(new FakeTransport(), maxXmlBytes: 100);
        $this->expectException(\IndexNowKit\Http\Exception\TransportException::class);
        $this->expectExceptionMessage('exceeds the 100 byte limit');
        iterator_to_array($reader->parse(str_repeat(' ', 101) . '<urlset/>'), false);
    }

    public function testNested404IsSkippedWithWarningButSiblingsStillRead(): void
    {
        $logger = new ArrayLogger();
        $t = new FakeTransport();
        $index = '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://www.example.com/missing.xml</loc></sitemap><sitemap><loc>https://www.example.com/ok.xml</loc></sitemap></sitemapindex>';
        $t->onGet('https://www.example.com/sitemap.xml', new Response(200, $index));
        $t->onGet('https://www.example.com/ok.xml', new Response(200, '<?xml version="1.0"?><urlset ' . self::NS . '><url><loc>https://www.example.com/a</loc></url></urlset>'));

        $entries = iterator_to_array((new SitemapReader($t, logger: $logger))->read('https://www.example.com/sitemap.xml'), false);

        self::assertCount(1, $entries);
        self::assertCount(1, $logger->messages('warning'));
    }

    public function testRoot404Throws(): void
    {
        $this->expectException(TransportException::class);
        iterator_to_array((new SitemapReader(new FakeTransport()))->read('https://www.example.com/missing.xml'), false);
    }

    public function testDepthLimitSkipsSitemapsBeyondMaxDepth(): void
    {
        $logger = new ArrayLogger();
        $t = new FakeTransport();
        $level1 = '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://www.example.com/level2.xml</loc></sitemap></sitemapindex>';
        $level2 = '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://www.example.com/level3.xml</loc></sitemap></sitemapindex>';
        $t->onGet('https://www.example.com/root.xml', new Response(200, $level1));
        $t->onGet('https://www.example.com/level2.xml', new Response(200, $level2));

        $entries = iterator_to_array((new SitemapReader($t, maxDepth: 1, logger: $logger))->read('https://www.example.com/root.xml'), false);

        self::assertSame([], $entries);
        self::assertStringContainsString('nested deeper than', implode("\n", $logger->messages('warning')));
    }

    public function testMaxSitemapsCapsHowManyAreFetched(): void
    {
        $logger = new ArrayLogger();
        $t = new FakeTransport();
        $sitemaps = '';
        for ($i = 1; $i <= 5; ++$i) {
            $sitemaps .= '<sitemap><loc>https://www.example.com/s' . $i . '.xml</loc></sitemap>';
        }
        $t->onGet('https://www.example.com/root.xml', new Response(200, '<?xml version="1.0"?><sitemapindex ' . self::NS . '>' . $sitemaps . '</sitemapindex>'));
        for ($i = 1; $i <= 5; ++$i) {
            $t->onGet('https://www.example.com/s' . $i . '.xml', new Response(200, '<?xml version="1.0"?><urlset ' . self::NS . '><url><loc>https://www.example.com/p' . $i . '</loc></url></urlset>'));
        }

        $entries = iterator_to_array((new SitemapReader($t, maxSitemaps: 3, logger: $logger))->read('https://www.example.com/root.xml'), false);

        self::assertCount(2, $entries, 'root (1) + 2 nested sitemaps = 3, the cap is then reached');
        self::assertStringContainsString('more than 3 sitemaps', implode("\n", $logger->messages('warning')));
    }

    public function testInvalidXmlThrows(): void
    {
        $this->expectException(TransportException::class);
        iterator_to_array((new SitemapReader(new FakeTransport()))->parse('<not valid xml'), false);
    }

    public function testTruncatedGzipThrows(): void
    {
        $good = (string) gzencode(self::URLSET);
        $truncated = substr($good, 0, (int) (\strlen($good) / 2));

        $this->expectException(TransportException::class);
        iterator_to_array((new SitemapReader(new FakeTransport()))->parse($truncated), false);
    }

    public function testNamespaceLessSitemapIsParsed(): void
    {
        $entries = iterator_to_array((new SitemapReader(new FakeTransport()))->parse('<?xml version="1.0"?><urlset><url><loc>https://www.example.com/a</loc></url></urlset>'), false);

        self::assertCount(1, $entries);
        self::assertSame('https://www.example.com/a', $entries[0]->url);
    }

    public function testCdataLocIsRead(): void
    {
        $entries = iterator_to_array((new SitemapReader(new FakeTransport()))->parse('<?xml version="1.0"?><urlset ' . self::NS . '><url><loc><![CDATA[https://www.example.com/cdata]]></loc></url></urlset>'), false);

        self::assertCount(1, $entries);
        self::assertSame('https://www.example.com/cdata', $entries[0]->url);
    }

    public function testInvalidLastmodBecomesNull(): void
    {
        $entries = iterator_to_array((new SitemapReader(new FakeTransport()))->parse('<?xml version="1.0"?><urlset ' . self::NS . '><url><loc>https://www.example.com/a</loc><lastmod>not-a-date</lastmod></url></urlset>'), false);

        self::assertNull($entries[0]->lastmod);
    }

    public function testXxePayloadIsNotExpandedAndIsNotFetched(): void
    {
        $t = new FakeTransport();
        $xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "https://attacker.example.com/xxe">]><urlset ' . self::NS . '><url><loc>&xxe;</loc></url></urlset>';

        $entries = iterator_to_array((new SitemapReader($t))->parse($xxe), false);

        self::assertSame([], $entries, 'the unexpanded entity leaves <loc> empty, so no entry is yielded');
        self::assertSame([], $t->gets, 'the external entity URL must never be fetched');
    }
}
