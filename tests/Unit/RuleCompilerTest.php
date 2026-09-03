<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Attribute\IndexNowUrl;
use IndexNowKit\Attribute\RuleCompiler;
use IndexNowKit\Attribute\RuleSource;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[IndexNow(route: 'post_show', params: ['slug' => 'slug'])]
#[IndexNow(via: 'category')]
#[IndexNow(urls: ['/a', '/b'])]
final class RepeatableRulesPost
{
    public function __construct(public string $slug = 'x') {}
}

#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title'], events: ['created', 'updated'], locales: 'all')]
#[IndexNow(route: 'inherited_show', params: ['slug' => 'slug'])]
#[IndexNow(route: 'amp_show', params: ['slug' => 'slug'], when: 'hasAmp', fields: [])]
class DefaultsRootPost
{
    public function __construct(public string $slug = 'x') {}
}

final class DefaultsLeafPost extends DefaultsRootPost {}

#[IndexNow(route: 'base_show', params: ['slug' => 'slug'])]
#[IndexNow(urls: ['/'])]
class InheritanceRootEntity
{
    public function __construct(public string $slug = 'x') {}
}

#[IndexNow(route: 'child_show', params: ['slug' => 'slug'], name: 'base_show')]
#[IndexNow(via: 'category')]
final class InheritanceChildEntity extends InheritanceRootEntity {}

final class MethodUrlPost
{
    #[IndexNowUrl]
    public function getPublicUrl(): string
    {
        return '/offers/x';
    }
}

final class MethodUrlWithArgPost
{
    #[IndexNowUrl]
    public function getPublicUrl(string $required): string
    {
        return '/offers/' . $required;
    }
}

final class RuleCompilerFromAttributesSubject {}

final class RuleCompilerTest extends TestCase
{
    public function testRepeatableRulesCompileInDeclarationOrderWithDefaultNames(): void
    {
        $ruleSet = (new AttributeReader())->rules(RepeatableRulesPost::class);

        self::assertCount(3, $ruleSet);
        $names = array_map(static fn($r) => $r->name, $ruleSet->rules);
        self::assertSame(['post_show', 'via:category', 'urls:/a,/b'], $names);
    }

    public function testDefaultNamesPerSource(): void
    {
        $ruleSet = RuleCompiler::fromAttributes(RuleCompilerFromAttributesSubject::class, [
            new IndexNow(route: 'r1'),
            new IndexNow(via: 'x'),
            new IndexNow(urls: ['/a', '/b', '/c']),
        ]);

        self::assertSame(['r1', 'via:x', 'urls:/a,/b'], array_map(static fn($r) => $r->name, $ruleSet->rules));
    }

    public function testDuplicateDefaultNameGetsNumericSuffix(): void
    {
        $ruleSet = RuleCompiler::fromAttributes(RuleCompilerFromAttributesSubject::class, [
            new IndexNow(route: 'same'),
            new IndexNow(route: 'same'),
            new IndexNow(route: 'same'),
        ]);

        self::assertSame(['same', 'same#2', 'same#3'], array_map(static fn($r) => $r->name, $ruleSet->rules));
    }

    public function testNameOverridesTheDefault(): void
    {
        $ruleSet = RuleCompiler::fromAttributes(RuleCompilerFromAttributesSubject::class, [new IndexNow(route: 'r1', name: 'custom-name')]);

        self::assertSame('custom-name', $ruleSet->rules[0]->name);
    }

    public function testDefaultsWhenIsAndedWithRuleWhen(): void
    {
        $ruleSet = (new AttributeReader())->rules(DefaultsRootPost::class);

        $inherited = $ruleSet->get('inherited_show');
        self::assertNotNull($inherited);
        self::assertSame(['isPublished'], $inherited->when);

        $amp = $ruleSet->get('amp_show');
        self::assertNotNull($amp);
        self::assertSame(['isPublished', 'hasAmp'], $amp->when);
    }

    public function testDefaultsFieldsEventsAndLocalesAreInheritedUnlessOverridden(): void
    {
        $ruleSet = (new AttributeReader())->rules(DefaultsRootPost::class);

        $inherited = $ruleSet->get('inherited_show');
        self::assertNotNull($inherited);
        self::assertSame(['slug', 'title'], $inherited->fields);
        self::assertSame([Event::Created, Event::Updated], $inherited->events);
        self::assertSame('all', $inherited->locales);
    }

    public function testEmptyFieldsOnARuleMeansNoFilterEvenWithDefaults(): void
    {
        $ruleSet = (new AttributeReader())->rules(DefaultsRootPost::class);

        $amp = $ruleSet->get('amp_show');
        self::assertNotNull($amp);
        self::assertSame([], $amp->fields, 'fields: [] on the rule overrides the inherited default filter');
    }

    public function testDefaultsAreInheritedBySubclassesWithoutRedeclaration(): void
    {
        $leaf = (new AttributeReader())->rules(DefaultsLeafPost::class);
        $root = (new AttributeReader())->rules(DefaultsRootPost::class);

        self::assertEquals($root->get('inherited_show'), $leaf->get('inherited_show'));
    }

    public function testInheritanceRootToLeafSameNameOverrideAndAddedRules(): void
    {
        $ruleSet = (new AttributeReader())->rules(InheritanceChildEntity::class);

        self::assertCount(3, $ruleSet);
        $names = array_map(static fn($r) => $r->name, $ruleSet->rules);
        self::assertSame(['base_show', 'urls:/', 'via:category'], $names, 'overridden rule keeps its original position');

        $overridden = $ruleSet->get('base_show');
        self::assertNotNull($overridden);
        self::assertSame('child_show', $overridden->route, 'child redefinition wins over the parent one');
        self::assertSame(RuleSource::Route, $overridden->source);
    }

    public function testMethodAttributeCompilesToUrlRuleNamedAfterTheMethod(): void
    {
        $ruleSet = (new AttributeReader())->rules(MethodUrlPost::class);

        self::assertCount(1, $ruleSet);
        $rule = $ruleSet->rules[0];
        self::assertSame('url:getPublicUrl', $rule->name);
        self::assertSame(RuleSource::Url, $rule->source);
        self::assertSame('getPublicUrl', $rule->url);
    }

    public function testMethodAttributeOnAMethodRequiringArgumentsThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        RuleCompiler::compile(new ReflectionClass(MethodUrlWithArgPost::class));
    }

    public function testNoSourceThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexNow();
    }

    public function testTwoSourcesThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexNow(route: 'r', via: 'x');
    }

    public function testUrlStartingWithSlashThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexNow(url: '/literal');
    }

    public function testUrlStartingWithHttpThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexNow(url: 'https://example.com/literal');
    }

    public function testUrlsEntryNotStartingWithSlashOrHttpThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexNow(urls: ['relativeAccessorLike']);
    }

    public function testUnknownEventThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexNow(route: 'r', events: ['not-an-event']);
    }
}
