<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;

final class ParamExtractorSubject
{
    // Read only through ParamExtractor's reflection-based closure trick, not directly from this class.
    // @phpstan-ignore property.onlyWritten
    private string $secret = 'hidden-value';

    public string $name = 'plain';

    public function isActive(): bool
    {
        return true;
    }

    public function hasChildren(): bool
    {
        return false;
    }
}

final class ParamExtractorWithNonObjectProperty
{
    public string $notAnObject = 'just a string';
}

final class ParamExtractorTest extends TestCase
{
    public function testReadsPrivatePropertyDirectlyWhenNoAccessorExists(): void
    {
        self::assertSame('hidden-value', ParamExtractor::read(new ParamExtractorSubject(), 'secret'));
    }

    public function testIsPrefixMethodIsUsedAsAccessor(): void
    {
        self::assertTrue(ParamExtractor::read(new ParamExtractorSubject(), 'active'));
    }

    public function testHasPrefixMethodIsUsedAsAccessor(): void
    {
        self::assertFalse(ParamExtractor::read(new ParamExtractorSubject(), 'children'));
    }

    public function testDottedPathThroughNonObjectValueThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        ParamExtractor::read(new ParamExtractorWithNonObjectProperty(), 'notAnObject.leaf');
    }

    public function testExtractReadsEveryParam(): void
    {
        $subject = new ParamExtractorSubject();

        self::assertSame(['name' => 'plain', 'active' => true], ParamExtractor::extract($subject, ['name' => 'name', 'active' => 'active']));
    }
}
