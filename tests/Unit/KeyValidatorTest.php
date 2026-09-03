<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Key\KeyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeyValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function validityProvider(): iterable
    {
        yield '7 chars, too short' => [str_repeat('a', 7), false];
        yield '8 chars, minimum valid' => [str_repeat('a', 8), true];
        yield '128 chars, maximum valid' => [str_repeat('a', 128), true];
        yield '129 chars, too long' => [str_repeat('a', 129), false];
        yield 'invalid character' => ['abcd!fgh', false];
        yield 'valid with dashes' => ['abcd-efgh-1234', true];
    }

    #[DataProvider('validityProvider')]
    public function testIsValid(string $key, bool $expected): void
    {
        self::assertSame($expected, KeyValidator::isValid($key));
    }

    public function testAssertValidThrowsForInvalidKey(): void
    {
        $this->expectException(ConfigurationException::class);
        KeyValidator::assertValid('short');
    }

    public function testAssertValidDoesNotThrowForValidKey(): void
    {
        KeyValidator::assertValid('abcdefgh');
        $this->addToAssertionCount(1);
    }

    public function testMaskShortKeyIsFullyStarred(): void
    {
        self::assertSame('****', KeyValidator::mask('abcd'));
        self::assertSame('**', KeyValidator::mask('ab'));
    }

    public function testMaskLongKeyKeepsFirstFourCharactersAndCapsStarsAtEight(): void
    {
        self::assertSame('abcd****', KeyValidator::mask('abcdefgh'));
        self::assertSame('aaaa********', KeyValidator::mask(str_repeat('a', 20)));
        self::assertSame('aaaa********', KeyValidator::mask(str_repeat('a', 128)));
    }
}
