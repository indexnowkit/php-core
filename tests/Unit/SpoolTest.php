<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Sitemap\Spool;
use IndexNowKit\Sitemap\SpoolMode;
use PHPUnit\Framework\TestCase;
use XMLReader;

final class SpoolTest extends TestCase
{
    public function testMemorySpoolIsReadableByXmlReaderThroughTheWrapper(): void
    {
        $spool = Spool::create(SpoolMode::Memory, null, 'test');
        fwrite($spool, '<?xml version="1.0"?><urlset><url><loc>https://www.example.com/a</loc></url></urlset>');

        $reader = XMLReader::open(Spool::uri($spool), 'UTF-8', LIBXML_NONET);
        self::assertInstanceOf(XMLReader::class, $reader);
        $locs = [];
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'loc') {
                $locs[] = $reader->readString();
            }
        }
        $reader->close();
        Spool::close($spool);

        self::assertSame(['https://www.example.com/a'], $locs);
        self::assertFalse(\is_resource($spool));
        self::assertFalse(@file_exists(Spool::SCHEME . '://1'), 'a closed spool is gone from the wrapper');
    }

    public function testDiskSpoolInACustomDirectoryIsRemovedOnClose(): void
    {
        $dir = sys_get_temp_dir() . '/indexnowkit-spool-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $spool = Spool::create(SpoolMode::Disk, $dir, 'test');
        fwrite($spool, 'x');

        self::assertSame(1, fstat($spool)['size']);
        Spool::close($spool);

        self::assertSame([], glob($dir . '/indexnow-sitemap-*') ?: [], 'nothing left behind');
        rmdir($dir);
    }

    public function testDiskModeFailsLoudlyWhenTheDirectoryIsNotWritable(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('does not exist');
        Spool::create(SpoolMode::Disk, '/nonexistent/indexnowkit', 'https://www.example.com/sitemap.xml');
    }

    public function testAutoFallsBackToMemoryAndReportsWhy(): void
    {
        $reasons = [];
        $spool = Spool::create(SpoolMode::Auto, '/nonexistent/indexnowkit', 'test', static function (string $problem) use (&$reasons): void {
            $reasons[] = $problem;
        });

        self::assertSame('MEMORY', stream_get_meta_data($spool)['stream_type']);
        self::assertCount(1, $reasons);
        self::assertStringContainsString('/nonexistent/indexnowkit does not exist', $reasons[0]);
        Spool::close($spool);
    }

    public function testProbeDiskReportsAWritableDefaultDirectoryAsFine(): void
    {
        self::assertNull(Spool::probeDisk(null));
        self::assertStringContainsString('does not exist', (string) Spool::probeDisk('/nonexistent/indexnowkit'));
    }

    public function testWrapperRejectsUnknownAndNonNumericIds(): void
    {
        Spool::close(Spool::create(SpoolMode::Memory, null, 'register the wrapper'));

        self::assertFalse(@fopen(Spool::SCHEME . '://999999999', 'r'));
        self::assertFalse(@fopen(Spool::SCHEME . '://../etc/passwd', 'r'));
    }
}
