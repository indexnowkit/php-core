<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\ChangeClassifier;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Attribute\IndexNowDefaults;
use IndexNowKit\Attribute\Param\Condition;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\Param\FieldCondition;
use IndexNowKit\Attribute\Param\Value;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\RuleCompiler;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use TypeError;

/** A condition that reads a field but does not say so: the classifier cannot see its old state. */
final readonly class OpenCondition implements Condition
{
    public function evaluate(object $subject): bool
    {
        return ParamExtractor::read($subject, 'state') === 'open';
    }
}

/** The same condition as a FieldCondition: the change set gives the old state. */
final readonly class OpenFieldCondition implements FieldCondition
{
    public function evaluate(object $subject): bool
    {
        return $this->heldFor(ParamExtractor::read($subject, 'state'));
    }

    public function field(): string
    {
        return 'state';
    }

    public function heldFor(mixed $oldValue): bool
    {
        return $oldValue === 'open';
    }
}

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

    #[TestDox('a custom Condition guards the rule; without FieldCondition the classifier evaluates it on the current object and misses the unpublish, unless whenFields names the field; a FieldCondition gets the exact old state')]
    public function testCustomConditions(): void
    {
        $offer = new class {
            public string $state = 'open';
        };
        $plain = RuleCompiler::fromAttributes($offer::class, [new IndexNow(urls: ['/o'], when: new OpenCondition())])->rules[0];
        self::assertTrue($plain->appliesTo($offer));
        self::assertFalse($plain->whenDependsOn('state'), 'a plain condition names no field');
        $offer->state = 'closed';
        self::assertFalse($plain->appliesTo($offer));
        self::assertNull(ChangeClassifier::classify($plain, $offer, ['state'], ['state' => ['open', 'closed']]), 'the old state is unknown: before is assumed equal to after (false), nothing to do');

        $declared = RuleCompiler::fromAttributes($offer::class, [new IndexNow(urls: ['/o'], when: new OpenCondition(), whenFields: ['state'])])->rules[0];
        self::assertSame(Event::Deleted, ChangeClassifier::classify($declared, $offer, ['state'], ['state' => ['open', 'closed']]), 'whenFields makes a change of the field a flip');

        $field = RuleCompiler::fromAttributes($offer::class, [new IndexNow(urls: ['/o'], when: new OpenFieldCondition())])->rules[0];
        self::assertTrue($field->whenDependsOn('state'));
        self::assertSame(Event::Deleted, ChangeClassifier::classify($field, $offer, ['state'], ['state' => ['open', 'closed']]));
        self::assertNull(ChangeClassifier::classify($field, $offer, ['state'], ['state' => ['reserved', 'closed']]), 'exact: it was not open before either');
        $offer->state = 'open';
        self::assertSame(Event::Created, ChangeClassifier::classify($field, $offer, ['state'], ['state' => ['closed', 'open']]));
        self::assertSame(Event::Updated, ChangeClassifier::classify($field, $offer, ['title'], ['title' => ['a', 'b']]));
    }

    public function testEqualsIsAConditionNotAParamValue(): void
    {
        $post = new class {
            public string $status = 'published';
            public string $slug = 's';
        };
        self::assertTrue((new Equals('status', 'published'))->evaluate($post));
        self::assertSame('status', (new Equals('status', 'published'))->field());
        self::assertTrue((new Equals('status', WhenStatus::Published))->heldFor('published'), 'an enum expected value matches its backing value');
        self::assertTrue((new Equals('status', 'published'))->heldFor(WhenStatus::Published));
        self::assertFalse((new Equals('status', 'published'))->heldFor('draft'));

        try {
            ParamExtractor::extract($post, ['slug' => 'slug', 'v' => new Equals('status', 'published')]);
            self::fail('a condition is not a param value');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('Param "v" of ' . $post::class . ' is a IndexNowKit\Attribute\Param\Equals, which is not a value source', $e->getMessage());
            self::assertStringContainsString('A condition such as Equals belongs to `when`, not `params`.', $e->getMessage());
        }

        $this->expectException(TypeError::class);
        new IndexNow(urls: ['/'], when: new Value(true)); // @phpstan-ignore argument.type (a ParamValue is no longer a condition)
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
