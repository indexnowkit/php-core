<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Key\KeyGenerator;
use PHPUnit\Framework\TestCase;

final class KeyGeneratorTest extends TestCase
{
    public function testDefaultIsThirtyTwoHexCharacters(): void
    {
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', KeyGenerator::generate());
    }

    public function testNonHexUsesFullAlphabet(): void
    {
        $key = KeyGenerator::generate(64, hex: false);

        self::assertSame(64, \strlen($key));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $key);
    }

    public function testCustomLength(): void
    {
        self::assertSame(8, \strlen(KeyGenerator::generate(8)));
        self::assertSame(128, \strlen(KeyGenerator::generate(128)));
    }

    public function testLengthBelowMinimumThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyGenerator::generate(7);
    }

    public function testLengthAboveMaximumThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        KeyGenerator::generate(129);
    }

    public function testGeneratedKeysAreUnique(): void
    {
        $keys = [];
        for ($i = 0; $i < 20; ++$i) {
            $keys[KeyGenerator::generate()] = true;
        }

        self::assertCount(20, $keys);
    }
}
