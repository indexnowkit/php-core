<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use PHPUnit\Framework\TestCase;

#[IndexNowAttribute(route: 'post_show', params: ['slug' => 'slug'])]
class AttributePost
{
    public function __construct(public string $slug = 'hello') {}
}

final class AttributeChildPost extends AttributePost {}

final class NotAnnotated {}

/**
 * AttributeReader is a thin, per-class-cached wrapper around RuleCompiler::compile(); the compilation
 * behaviour itself (inheritance, defaults merging, naming) is covered by RuleCompilerTest.
 */
final class AttributeTest extends TestCase
{
    public function testRulesAreCompiledAndInherited(): void
    {
        $reader = new AttributeReader();

        $ruleSet = $reader->rules(AttributeChildPost::class);

        self::assertCount(1, $ruleSet);
        self::assertSame('post_show', $ruleSet->get('post_show')?->route);
    }

    public function testRulesAcceptsAnObjectInstanceToo(): void
    {
        $reader = new AttributeReader();

        self::assertSame('post_show', $reader->rules(new AttributePost())->get('post_show')?->route);
    }

    public function testUnannotatedClassYieldsAnEmptyRuleSet(): void
    {
        $reader = new AttributeReader();

        $ruleSet = $reader->rules(NotAnnotated::class);

        self::assertTrue($ruleSet->isEmpty());
        self::assertCount(0, $ruleSet);
    }

    public function testCompiledRuleSetIsCachedPerClass(): void
    {
        $reader = new AttributeReader();

        self::assertSame($reader->rules(AttributePost::class), $reader->rules(AttributePost::class));
    }
}
