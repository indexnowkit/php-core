<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\ChangeClassifier;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\RuleCompiler;
use IndexNowKit\Event;
use PHPUnit\Framework\TestCase;

enum WhenStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

final class WhenConditionTest extends TestCase
{
    public function testEqualsConditionOnStringStatus(): void
    {
        $post = new class {
            public string $post_status = 'draft';
        };
        $rules = RuleCompiler::fromAttributes($post::class, [new IndexNow(urls: ['/'], when: new Equals('post_status', 'publish'))]);
        $rule = $rules->rules[0];

        self::assertFalse($rule->appliesTo($post), 'a non-empty string status must not count as truthy');
        $post->post_status = 'publish';
        self::assertTrue($rule->appliesTo($post));
        self::assertTrue($rule->whenDependsOn('post_status'));
    }

    public function testEqualsConditionOnEnumAndOldStateDetection(): void
    {
        $post = new class {
            public WhenStatus $status = WhenStatus::Draft;
        };
        $rule = RuleCompiler::fromAttributes($post::class, [new IndexNow(urls: ['/p'], when: new Equals('status', WhenStatus::Published))])->rules[0];

        self::assertFalse($rule->appliesTo($post));
        self::assertSame(Event::Deleted, ChangeClassifier::classify($rule, $post, ['status'], ['status' => [WhenStatus::Published, WhenStatus::Draft]]));
        $post->status = WhenStatus::Published;
        self::assertSame(Event::Created, ChangeClassifier::classify($rule, $post, ['status'], ['status' => [WhenStatus::Draft, WhenStatus::Published]]));
        self::assertSame(Event::Updated, ChangeClassifier::classify($rule, $post, ['title'], ['title' => ['a', 'b']]));
    }

    public function testClosureConditionForRuntimeRules(): void
    {
        $post = new class {
            public string $post_status = 'publish';
        };
        $rule = RuleCompiler::fromAttributes($post::class, [new IndexNow(urls: ['/'], when: static fn(object $p): bool => $p->post_status === 'publish', whenFields: ['post_status'])])->rules[0];

        self::assertTrue($rule->appliesTo($post));
        self::assertTrue($rule->whenDependsOn('post_status'));
        $post->post_status = 'draft';
        // old value unknowable for a closure: a change of a declared whenField is assumed to have flipped the outcome
        self::assertSame(Event::Deleted, ChangeClassifier::classify($rule, $post, ['post_status'], ['post_status' => ['publish', 'draft']]));
    }

    public function testWhenFieldsOfOneConditionDoNotMakeAnotherConditionUnknown(): void
    {
        $post = new class {
            public bool $published = false;
            public bool $ampEnabled = false;

            public function isPublished(): bool
            {
                return $this->published;
            }

            public function hasAmp(): bool
            {
                return $this->ampEnabled;
            }
        };
        $rule = RuleCompiler::fromAttributes($post::class, [new IndexNow(urls: ['/amp'], when: 'hasAmp', whenFields: ['ampEnabled'])], new IndexNowDefaults(when: 'isPublished'))->rules[0];

        // never published: toggling the AMP flag must not be mistaken for an unpublish (was a pooled-whenFields bug)
        $post->ampEnabled = true;
        self::assertNull(ChangeClassifier::classify($rule, $post, ['ampEnabled'], ['ampEnabled' => [false, true]]));
        $post->ampEnabled = false;
        self::assertNull(ChangeClassifier::classify($rule, $post, ['ampEnabled'], ['ampEnabled' => [true, false]]));
        self::assertSame([['ampEnabled']], array_values(array_filter($rule->whenFields)), 'the field belongs to the hasAmp condition only');
    }
}
