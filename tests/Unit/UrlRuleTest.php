<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\RuleSource;
use IndexNowKit\Attribute\UrlRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlRuleWhenSubject
{
    public function __construct(private bool $published = true, private bool $visible = true) {}

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }
}

final class UrlRuleTest extends TestCase
{
    /**
     * @param list<string> $when
     * @param list<string> $whenFields
     * @param list<string> $fields
     */
    private static function rule(array $when = [], array $whenFields = [], array $fields = []): UrlRule
    {
        return new UrlRule(
            name: 'r',
            source: RuleSource::Route,
            route: 'r',
            when: $when,
            whenFields: $whenFields,
            fields: $fields,
        );
    }

    public function testCaresAboutTrueOnExactMatch(): void
    {
        $rule = self::rule(fields: ['title']);

        self::assertTrue($rule->caresAbout(['title']));
    }

    public function testCaresAboutFalseWhenNoOverlap(): void
    {
        $rule = self::rule(fields: ['title']);

        self::assertFalse($rule->caresAbout(['views']));
    }

    public function testCaresAboutMatchesDeclaredPrefixOfAChangedDottedKey(): void
    {
        $rule = self::rule(fields: ['address']);

        self::assertTrue($rule->caresAbout(['address.city']));
    }

    public function testCaresAboutMatchesChangedPrefixOfADeclaredDottedField(): void
    {
        $rule = self::rule(fields: ['address.city']);

        self::assertTrue($rule->caresAbout(['address']));
    }

    public function testCaresAboutIsTrueForAnyChangeWhenFieldsIsEmpty(): void
    {
        $rule = self::rule(fields: []);

        self::assertTrue($rule->caresAbout(['anything']));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function fieldCandidatesProvider(): iterable
    {
        yield 'is-prefixed getter' => ['isPublished', ['isPublished', 'published', 'is_published']];
        yield 'get-prefixed getter' => ['getStatus', ['getStatus', 'status']];
        yield 'has-prefixed getter' => ['hasAmp', ['hasAmp', 'amp', 'has_amp']];
        yield 'plain property name' => ['title', ['title']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('fieldCandidatesProvider')]
    public function testFieldCandidates(string $accessor, array $expected): void
    {
        self::assertSame($expected, UrlRule::fieldCandidates($accessor));
    }

    public function testAppliesToRequiresEveryWhenAccessorToBeTruthy(): void
    {
        $rule = self::rule(when: ['isPublished', 'isVisible']);

        self::assertTrue($rule->appliesTo(new UrlRuleWhenSubject(published: true, visible: true)));
        self::assertFalse($rule->appliesTo(new UrlRuleWhenSubject(published: true, visible: false)));
        self::assertFalse($rule->appliesTo(new UrlRuleWhenSubject(published: false, visible: true)));
    }

    public function testAppliesToIsTrueWhenThereIsNoWhenAccessor(): void
    {
        $rule = self::rule(when: []);

        self::assertTrue($rule->appliesTo(new UrlRuleWhenSubject(published: false, visible: false)));
    }

    public function testWhenDependsOnDeclaredWhenFields(): void
    {
        $rule = self::rule(when: ['isPublished'], whenFields: ['status']);

        self::assertTrue($rule->whenDependsOn('status'));
    }

    public function testWhenDependsOnFieldCandidateOfAWhenAccessor(): void
    {
        $rule = self::rule(when: ['isPublished']);

        self::assertTrue($rule->whenDependsOn('published'));
        self::assertTrue($rule->whenDependsOn('is_published'));
        self::assertTrue($rule->whenDependsOn('isPublished'));
    }

    public function testWhenDependsOnFalseForUnrelatedField(): void
    {
        $rule = self::rule(when: ['isPublished'], whenFields: ['status']);

        self::assertFalse($rule->whenDependsOn('views'));
    }
}
