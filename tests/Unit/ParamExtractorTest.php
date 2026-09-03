<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use DateTimeImmutable;
use IndexNowKit\Attribute\Param\Accessor;
use IndexNowKit\Attribute\Param\Call;
use IndexNowKit\Attribute\Param\Formatted;
use IndexNowKit\Attribute\Param\Placeholder;
use IndexNowKit\Attribute\Param\Value;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;
use Stringable;

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

enum ParamExtractorStatus: string
{
    case Published = 'published';
}

final class ParamExtractorStringableTag implements Stringable
{
    public function __toString(): string
    {
        return 'str-value';
    }
}

final class ParamExtractorTypesSubject
{
    public DateTimeImmutable $publishedAt;

    public ParamExtractorStatus $status = ParamExtractorStatus::Published;

    public ParamExtractorStringableTag $tag;

    public function __construct()
    {
        $this->publishedAt = new DateTimeImmutable('2024-06-15');
        $this->tag = new ParamExtractorStringableTag();
    }

    public function slugFor(?string $locale, ?string $host = null): string
    {
        return \sprintf('slug-%s-%s', $locale ?? 'none', $host ?? 'nohost');
    }
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

    public function testValueResolvesToItsConstant(): void
    {
        self::assertSame('html', ParamExtractor::resolve(new ParamExtractorTypesSubject(), new Value('html')));
    }

    public function testFormattedFormatsTheDateBehindTheAccessor(): void
    {
        self::assertSame('2024', ParamExtractor::resolve(new ParamExtractorTypesSubject(), new Formatted('publishedAt', 'Y')));
    }

    public function testFormattedOnANonDateAccessorThrowsMentioningFormatted(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Formatted/');
        ParamExtractor::resolve(new ParamExtractorTypesSubject(), new Formatted('tag', 'Y'));
    }

    public function testCallReceivesTheLocalePlaceholder(): void
    {
        $value = ParamExtractor::resolve(new ParamExtractorTypesSubject(), new Call('slugFor', Placeholder::Locale), locale: 'fr');

        self::assertSame('slug-fr-nohost', $value);
    }

    public function testCallReceivesTheHostPlaceholder(): void
    {
        $value = ParamExtractor::resolve(new ParamExtractorTypesSubject(), new Call('slugFor', Placeholder::Locale, Placeholder::Host), locale: 'fr', host: 'example.com');

        self::assertSame('slug-fr-example.com', $value);
    }

    public function testCallOnAnUnknownMethodThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        ParamExtractor::resolve(new ParamExtractorTypesSubject(), new Call('nope'));
    }

    public function testAccessorReadsThePropertyOrGetter(): void
    {
        self::assertSame(ParamExtractorStatus::Published, ParamExtractor::resolve(new ParamExtractorTypesSubject(), new Accessor('status')));
    }

    public function testExtractCoercesBackedEnumToItsValue(): void
    {
        self::assertSame(['s' => 'published'], ParamExtractor::extract(new ParamExtractorTypesSubject(), ['s' => 'status']));
    }

    public function testExtractCoercesStringableToString(): void
    {
        self::assertSame(['t' => 'str-value'], ParamExtractor::extract(new ParamExtractorTypesSubject(), ['t' => 'tag']));
    }

    public function testExtractOfADateWithoutFormattedThrowsMentioningFormatted(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Formatted/');
        ParamExtractor::extract(new ParamExtractorTypesSubject(), ['p' => 'publishedAt']);
    }

    public function testExtractKeepsAPlainObjectForRouteModelBinding(): void
    {
        $subject = new ParamExtractorTypesSubject();

        self::assertSame(['post' => $subject], ParamExtractor::extract($subject, ['post' => ParamExtractor::SELF]));
    }
}
