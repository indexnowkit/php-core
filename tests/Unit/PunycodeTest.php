<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Url\Punycode;
use PHPUnit\Framework\TestCase;

final class PunycodeTest extends TestCase
{
    public function testKnownVectors(): void
    {
        self::assertSame('xn--80aswg.xn--p1ai', Punycode::encodeHost('сайт.рф'));
        self::assertSame('xn--mnchen-3ya.de', Punycode::encodeHost('münchen.de'));
        self::assertSame('www.example.com', Punycode::encodeHost('www.example.com'));
        self::assertSame('xn--e1afmkfd.xn--80akhbyknj4f', Punycode::encodeHost('пример.испытание'));
    }
}
