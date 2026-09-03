<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use DateTimeImmutable;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\Response;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Sitemap\SpoolMode;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
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

    public function testForeignHostsAreFollowedWhenAllowedByConstructorOrPerCall(): void
    {
        $t = new FakeTransport();
        $index = '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://cdn.example.net/s1.xml</loc></sitemap><sitemap><loc>ftp://cdn.example.net/s2.xml</loc></sitemap></sitemapindex>';
        $t->onGet('https://www.example.com/sitemap.xml', new Response(200, $index));
        $t->onGet('https://cdn.example.net/s1.xml', new Response(200, self::URLSET));
        $logger = new ArrayLogger();

        $entries = iterator_to_array((new SitemapReader($t, allowForeignHosts: true, logger: $logger))->read('https://www.example.com/sitemap.xml'), false);
        self::assertCount(3, $entries);
        self::assertNotContains('ftp://cdn.example.net/s2.xml', $t->gets, 'only http(s) sitemaps are ever fetched, foreign hosts or not');
        self::assertStringContainsString('not an http(s) URL', implode("\n", $logger->messages('warning')));

        $t->gets = [];
        $entries = iterator_to_array((new SitemapReader($t))->read('https://www.example.com/sitemap.xml', allowForeignHosts: true), false);
        self::assertCount(3, $entries, 'the per-call flag overrides the constructor default');

        $entries = iterator_to_array((new SitemapReader($t, allowForeignHosts: true))->read('https://www.example.com/sitemap.xml', allowForeignHosts: false), false);
        self::assertSame([], $entries, 'and the other way round');
    }

    public function testStreamsThroughDownloadWhenTheTransportSupportsItAndBuffersOtherwise(): void
    {
        $streaming = new FakeTransport();
        $streaming->onGet('https://www.example.com/sitemap.xml', new Response(200, self::URLSET));
        self::assertCount(3, iterator_to_array((new SitemapReader($streaming))->read('https://www.example.com/sitemap.xml'), false));
        self::assertSame(['https://www.example.com/sitemap.xml'], $streaming->downloads);

        $buffering = new class ($streaming) implements TransportInterface {
            public function __construct(private readonly FakeTransport $inner) {}

            public function post(string $url, string $json, array $headers = []): Response
            {
                return $this->inner->post($url, $json, $headers);
            }

            public function get(string $url): Response
            {
                return $this->inner->get($url);
            }
        };
        $streaming->downloads = [];
        self::assertCount(3, iterator_to_array((new SitemapReader($buffering))->read('https://www.example.com/sitemap.xml'), false));
        self::assertSame([], $streaming->downloads, 'a plain TransportInterface is read with get()');
    }

    public function testDecompressedSizeOverTheLimitIsRejectedWhileInflating(): void
    {
        $reader = new SitemapReader(new FakeTransport(), maxXmlBytes: 1000);
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('decompressed size exceeds 1000 bytes');
        iterator_to_array($reader->parse((string) gzencode(str_repeat(' ', 5000) . '<urlset/>')), false);
    }

    public function testAbandonedGeneratorReleasesItsTempFiles(): void
    {
        $t = new FakeTransport();
        $index = '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://www.example.com/s1.xml.gz</loc></sitemap></sitemapindex>';
        $t->onGet('https://www.example.com/sitemap.xml', new Response(200, $index));
        $t->onGet('https://www.example.com/s1.xml.gz', new Response(200, (string) gzencode(self::URLSET)));
        $before = \count((array) get_resources('stream'));

        foreach ((new SitemapReader($t))->read('https://www.example.com/sitemap.xml') as $entry) {
            break; // stop after the first entry: three spools (root, gzip, inflated) are open here
        }
        gc_collect_cycles();

        self::assertSame($before, \count((array) get_resources('stream')), 'every temp file is closed when the generator is destroyed');
    }

    public function testMemorySpoolReadsGzipAndIndexesWithoutTouchingTheDisk(): void
    {
        $t = new FakeTransport();
        $index = '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://www.example.com/s1.xml.gz</loc></sitemap></sitemapindex>';
        $t->onGet('https://www.example.com/sitemap.xml', new Response(200, $index));
        $t->onGet('https://www.example.com/s1.xml.gz', new Response(200, (string) gzencode(self::URLSET)));

        $entries = iterator_to_array((new SitemapReader($t, spool: SpoolMode::Memory))->read('https://www.example.com/sitemap.xml'), false);

        self::assertCount(3, $entries);
    }

    public function testNetworkFailureAndServerErrorAreRetriedButClientErrorsAreNot(): void
    {
        $sleeps = [];
        $sleep = static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        };
        $logger = new ArrayLogger();
        $t = new FakeTransport();
        $t->onGet('https://www.example.com/sitemap.xml', FakeTransport::failing('connection reset'), new Response(503), new Response(200, self::URLSET));

        $entries = iterator_to_array((new SitemapReader($t, logger: $logger, fetchRetries: 2, sleep: $sleep))->read('https://www.example.com/sitemap.xml'), false);

        self::assertCount(3, $entries);
        self::assertSame([1, 2], $sleeps, 'backoff 1 s then 2 s');
        self::assertCount(2, $logger->messages('info'));

        $t->onGet('https://www.example.com/gone.xml', new Response(404), new Response(200, self::URLSET));
        $sleeps = [];
        try {
            iterator_to_array((new SitemapReader($t, fetchRetries: 2, sleep: $sleep))->read('https://www.example.com/gone.xml'), false);
            self::fail('a 404 must not be retried');
        } catch (TransportException $e) {
            self::assertStringContainsString('HTTP 404', $e->getMessage());
            self::assertSame([], $sleeps);
        }

        $t->onGet('https://www.example.com/down.xml', FakeTransport::failing('down'), FakeTransport::failing('down'), FakeTransport::failing('still down'));
        $sleeps = [];
        try {
            iterator_to_array((new SitemapReader($t, fetchRetries: 2, sleep: $sleep))->read('https://www.example.com/down.xml'), false);
            self::fail('retries are bounded');
        } catch (TransportException $e) {
            self::assertStringContainsString('still down', $e->getMessage(), 'the last error is reported');
            self::assertSame([1, 2], $sleeps);
        }
    }

    public function testTruncatedXmlIsReportedAsEndingEarly(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('ends early');
        iterator_to_array((new SitemapReader(new FakeTransport()))->parse('<?xml version="1.0"?><urlset ' . self::NS . '><url><loc>https://www.example.com/a</loc></url><url><loc>https://www.exam'), false);
    }

    public function testLocalFilesAreReadWithoutTheTransport(): void
    {
        $dir = sys_get_temp_dir() . '/indexnowkit-local-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/part.xml.gz', (string) gzencode(self::URLSET));
        file_put_contents($dir . '/index.xml', '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>' . $dir . '/part.xml.gz</loc></sitemap><sitemap><loc>https://www.example.com/remote.xml</loc></sitemap></sitemapindex>');
        $t = new FakeTransport();
        $t->onGet('https://www.example.com/remote.xml', new Response(200, '<?xml version="1.0"?><urlset ' . self::NS . '><url><loc>https://www.example.com/remote</loc></url></urlset>'));
        $logger = new ArrayLogger();

        $entries = iterator_to_array((new SitemapReader($t, logger: $logger))->read($dir . '/index.xml'), false);
        self::assertCount(3, $entries, 'the local gzip part is read; the remote part is skipped');
        self::assertSame([], $t->gets);
        self::assertStringContainsString('give the index by URL, or allow foreign hosts', implode("\n", $logger->messages('warning')));

        $entries = iterator_to_array((new SitemapReader($t))->read('file://' . $dir . '/index.xml', allowForeignHosts: true), false);
        self::assertCount(4, $entries, 'with foreign hosts allowed the remote part is fetched');

        try {
            iterator_to_array((new SitemapReader($t))->read($dir . '/missing.xml'), false);
            self::fail('a missing file is an error');
        } catch (TransportException $e) {
            self::assertStringContainsString('no such file', $e->getMessage());
        }
        unlink($dir . '/part.xml.gz');
        unlink($dir . '/index.xml');
        rmdir($dir);
    }

    public function testTextSitemapsYieldOneUrlPerLineWithoutLastmod(): void
    {
        $logger = new ArrayLogger();
        $reader = new SitemapReader(new FakeTransport(), logger: $logger);
        $text = "\xEF\xBB\xBFhttps://www.example.com/a\r\n\n# comment\nhttps://www.example.com/b\nnot a url\n";

        $entries = iterator_to_array($reader->parse($text), false);
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], array_map(static fn($e): string => $e->url, $entries));
        self::assertNull($entries[0]->lastmod);
        self::assertStringContainsString('line 5 is not an http(s) URL', implode("\n", $logger->messages('warning')));

        self::assertSame([], iterator_to_array($reader->parse($text, '', new DateTimeImmutable('-1 day')), false), 'no lastmod: nothing is "changed since"');
        self::assertCount(2, iterator_to_array($reader->parse((string) gzencode($text)), false), 'gzip text sitemaps too');
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

    public function testControlCharactersInARejectedLocAreEscapedInTheWarning(): void
    {
        $logger = new ArrayLogger();
        $t = new FakeTransport();
        $index = '<?xml version="1.0"?><sitemapindex ' . self::NS . '><sitemap><loc>https://other.example.com/x&#10;[CRITICAL] forged line</loc></sitemap></sitemapindex>';
        $t->onGet('https://www.example.com/sitemap.xml', new Response(200, $index));

        $entries = iterator_to_array((new SitemapReader($t, logger: $logger))->read('https://www.example.com/sitemap.xml'), false);

        self::assertSame([], $entries);
        $warning = implode('|', $logger->messages('warning'));
        self::assertStringNotContainsString("\n", $warning);
        self::assertStringContainsString('https://other.example.com/x\n[CRITICAL] forged line', $warning, 'the newline is escaped, the value is still recognizable');
    }
}
