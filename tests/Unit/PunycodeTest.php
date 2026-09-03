<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Url\Punycode;
use PHPUnit\Framework\TestCase;

/**
 * ext-intl is not installed in the Docker test image used for this suite, so these tests always
 * exercise the pure-PHP RFC 3492 encoder in Punycode, not idn_to_ascii(). CI additionally runs with
 * ext-intl enabled, covering the other branch.
 */
final class PunycodeTest extends TestCase
{
    public function testKnownVectors(): void
    {
        self::assertSame('xn--80aswg.xn--p1ai', Punycode::encodeHost('сайт.рф'));
        self::assertSame('xn--mnchen-3ya.de', Punycode::encodeHost('münchen.de'));
        self::assertSame('www.example.com', Punycode::encodeHost('www.example.com'));
        self::assertSame('xn--e1afmkfd.xn--80akhbyknj4f', Punycode::encodeHost('пример.испытание'));
    }

    public function testPureAsciiHostPassesThroughUnchanged(): void
    {
        self::assertSame('example.com', Punycode::encodeHost('example.com'));
    }

    public function testMixedCaseNonAsciiInputIsLowerCasedBeforeEncoding(): void
    {
        self::assertSame('xn--mnchen-3ya.de', Punycode::encodeHost('MÜNCHEN.de'));
    }

    public function testLabelLongerThanSixtyThreeCodePointsThrows(): void
    {
        $this->expectException(InvalidUrlException::class);
        Punycode::encodeHost(str_repeat('münchen', 20));
    }

    public function testInvalidUtf8Throws(): void
    {
        $this->expectException(InvalidUrlException::class);
        Punycode::encodeHost("m\xFCnchen");
    }
}
