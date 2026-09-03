<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Event;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\CallableUrlResolver;
use IndexNowKit\Url\GuardedUrlResolver;
use PHPUnit\Framework\TestCase;

#[IndexNowAttribute(route: 'x')]
final class GuardedRoutedPost {}

#[IndexNowAttribute(route: 'x', events: ['created'])]
final class CreatedOnlyPost {}

#[IndexNowAttribute(urls: ['/dup1'], name: 'a')]
#[IndexNowAttribute(urls: ['/dup1'], name: 'b')]
#[IndexNowAttribute(urls: ['/other'], name: 'c')]
final class DuplicateUrlsPost {}

final class NotAnnotatedGuarded {}

final class GuardedUrlResolverTest extends TestCase
{
    public function testNeverThrowsAndLogsErrorWithTheRuleNameOnAFailingRule(): void
    {
        $logger = new ArrayLogger();
        $resolver = new GuardedUrlResolver(new AttributeUrlResolver(new AttributeReader()), new AttributeReader(), $logger);

        $urls = $resolver->resolve(new GuardedRoutedPost(), Event::Updated);

        self::assertSame([], $urls);
        $errors = $logger->messages('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('rule "x"', $errors[0]);
    }

    public function testResolveRuleAlsoNeverThrowsAndLogsWithTheRuleName(): void
    {
        $logger = new ArrayLogger();
        $reader = new AttributeReader();
        $resolver = new GuardedUrlResolver(new AttributeUrlResolver($reader), $reader, $logger);
        $rule = $reader->rules(GuardedRoutedPost::class)->get('x');
        self::assertNotNull($rule);

        $resolved = $resolver->resolveRule(new GuardedRoutedPost(), $rule, Event::Updated);

        self::assertSame([], $resolved);
        self::assertStringContainsString('rule "x"', implode("\n", $logger->messages('error')));
    }

    public function testDebugLogWhenNothingApplies(): void
    {
        $logger = new ArrayLogger();
        $resolver = new GuardedUrlResolver(new AttributeUrlResolver(new AttributeReader()), new AttributeReader(), $logger);

        $urls = $resolver->resolve(new NotAnnotatedGuarded(), Event::Updated);

        self::assertSame([], $urls);
        self::assertStringContainsString('no rule applies', implode("\n", $logger->messages('debug')));
    }

    public function testCustomInnerResolverKeepsTheClassLevelEventSubscriptionCheck(): void
    {
        $logger = new ArrayLogger();
        $inner = new CallableUrlResolver(static fn(): array => ['/should-not-be-called']);
        $resolver = new GuardedUrlResolver($inner, new AttributeReader(), $logger);

        $urls = $resolver->resolve(new CreatedOnlyPost(), Event::Updated);

        self::assertSame([], $urls, 'the class does not subscribe to Updated, the custom resolver must not even run');
        self::assertStringContainsString('does not subscribe', implode("\n", $logger->messages('debug')));
    }

    public function testCustomInnerResolverStillRunsForAnUnannotatedObject(): void
    {
        $inner = new CallableUrlResolver(static fn(): array => ['/x']);
        $resolver = new GuardedUrlResolver($inner, new AttributeReader());

        $urls = $resolver->resolve(new NotAnnotatedGuarded(), Event::Updated);

        self::assertSame(['/x'], $urls);
    }

    public function testResolveDeduplicatesAcrossRules(): void
    {
        $resolver = new GuardedUrlResolver(new AttributeUrlResolver(new AttributeReader()), new AttributeReader());

        $urls = $resolver->resolve(new DuplicateUrlsPost(), Event::Updated);

        self::assertSame(['/dup1', '/other'], $urls);
    }
}
