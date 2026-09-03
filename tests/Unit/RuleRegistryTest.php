<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Attribute\RuleSet;
use IndexNowKit\Attribute\RuleSource;
use IndexNowKit\Attribute\UrlRule;
use PHPUnit\Framework\TestCase;

class RuleRegistryUnmappedClass {}

final class RuleRegistrySubclass extends RuleRegistryUnmappedClass {}

#[IndexNow(urls: ['/fallback'])]
final class RuleRegistryFallbackClass {}

final class RuleRegistryTest extends TestCase
{
    public function testRegisterCompilesRuntimeAttributesForTheGivenClass(): void
    {
        $registry = new RuleRegistry();
        $registry->register(RuleRegistryUnmappedClass::class, [new IndexNow(route: 'posts.show', params: ['post' => 'self'])], new IndexNowDefaults(when: 'isPublished'));

        $ruleSet = $registry->rules(RuleRegistryUnmappedClass::class);

        self::assertCount(1, $ruleSet);
        $rule = $ruleSet->get('posts.show');
        self::assertNotNull($rule);
        self::assertSame(['isPublished'], $rule->when);
    }

    public function testRegisteredRulesAreInheritedBySubclasses(): void
    {
        $registry = new RuleRegistry();
        $registry->register(RuleRegistryUnmappedClass::class, [new IndexNow(route: 'posts.show')]);

        $ruleSet = $registry->rules(RuleRegistrySubclass::class);

        self::assertCount(1, $ruleSet);
        self::assertSame('posts.show', $ruleSet->rules[0]->name);
    }

    public function testRegisterForFactoryReturningNullFallsThroughToTheInnerReader(): void
    {
        $registry = new RuleRegistry(new AttributeReader());
        $registry->registerFor(RuleRegistryFallbackClass::class, static fn(object $o): ?RuleSet => null);

        $ruleSet = $registry->rules(new RuleRegistryFallbackClass());

        self::assertCount(1, $ruleSet);
        self::assertSame('urls:/fallback', $ruleSet->rules[0]->name);
    }

    public function testRegisterForFactoryReturningARuleSetTakesPrecedenceOverAttributes(): void
    {
        $registry = new RuleRegistry(new AttributeReader());
        $custom = new RuleSet(RuleRegistryFallbackClass::class, [new UrlRule(name: 'custom', source: RuleSource::Urls, urls: ['/custom'])]);
        $registry->registerFor(RuleRegistryFallbackClass::class, static fn(object $o): RuleSet => $custom);

        $ruleSet = $registry->rules(new RuleRegistryFallbackClass());

        self::assertSame($custom, $ruleSet);
    }

    public function testRegisterForIsOnlyConsultedForObjectsNotClassStrings(): void
    {
        $registry = new RuleRegistry(new AttributeReader());
        $registry->registerFor(RuleRegistryFallbackClass::class, static fn(object $o): RuleSet => new RuleSet(RuleRegistryFallbackClass::class, []));

        $ruleSet = $registry->rules(RuleRegistryFallbackClass::class);

        self::assertCount(1, $ruleSet, 'a class-string query bypasses factories and reaches the inner attribute reader');
    }
}
