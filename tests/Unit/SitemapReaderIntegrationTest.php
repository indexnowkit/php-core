<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Sitemap\SitemapReader;
use IndexNowKit\Tests\Support\MockServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Regression test: Psr18Transport::get() used to truncate GET responses at the 2 KB POST-diagnostics
 * body limit. This reads a real >100 KB sitemap (and its gzip variant, served by
 * packages/core/tests/Support/mock-server/router.php) through a real PSR-18 client to prove every entry survives the
 * round trip.
 */
#[Group('integration')]
final class SitemapReaderIntegrationTest extends TestCase
{
    private static MockServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = MockServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    private function reader(): SitemapReader
    {
        $factory = new Psr17Factory();

        return new SitemapReader(new Psr18Transport(new Psr18Client(), $factory, $factory));
    }

    public function testReadsEveryEntryOfALargePlainSitemapThroughRealHttp(): void
    {
        $entries = iterator_to_array($this->reader()->read(self::$server->baseUrl() . '/sitemap.xml'), false);

        self::assertCount(3000, $entries);
        self::assertSame('https://www.example.com/page-0', $entries[0]->url);
        self::assertSame('https://www.example.com/page-2999', $entries[2999]->url);
    }

    public function testReadsEveryEntryOfAGzippedSitemapThroughRealHttp(): void
    {
        $entries = iterator_to_array($this->reader()->read(self::$server->baseUrl() . '/sitemap.xml.gz'), false);

        self::assertCount(3000, $entries);
        self::assertSame('https://www.example.com/page-2999', $entries[2999]->url);
    }
}
