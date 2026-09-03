<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Event;
use IndexNowKit\Tests\Support\ArrayLogger;
use IndexNowKit\Url\CallableUrlResolver;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\NullUrlResolver;
use IndexNowKit\Url\UrlResolverInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[IndexNowAttribute(route: 'x', when: 'published')]
final class GuardedPost
{
    public function __construct(public bool $published) {}
}

#[IndexNowAttribute(route: 'x', events: ['created'])]
final class CreatedOnlyPost {}

final class NotAnnotatedGuarded {}

final class ThrowingUrlResolver implements UrlResolverInterface
{
    public function resolve(object $subject, Event $event): iterable
    {
        throw new RuntimeException('boom');
    }
}

final class GuardedUrlResolverTest extends TestCase
{
    public function testNoAttributeReturnsEmpty(): void
    {
        $resolver = new GuardedUrlResolver(new NullUrlResolver(), new AttributeReader());

        self::assertSame([], $resolver->resolve(new NotAnnotatedGuarded(), Event::Updated));
    }

    public function testEventNotListenedToReturnsEmpty(): void
    {
        $resolver = new GuardedUrlResolver(new NullUrlResolver(), new AttributeReader());

        self::assertSame([], $resolver->resolve(new CreatedOnlyPost(), Event::Updated));
    }

    public function testWhenFalseBlocksNonDeleteEvents(): void
    {
        $inner = new CallableUrlResolver(static fn(): array => ['/x']);
        $resolver = new GuardedUrlResolver($inner, new AttributeReader());

        self::assertSame([], $resolver->resolve(new GuardedPost(false), Event::Updated));
    }

    public function testWhenFalseStillResolvesForDeletedEvent(): void
    {
        $inner = new CallableUrlResolver(static fn(): array => ['/x']);
        $resolver = new GuardedUrlResolver($inner, new AttributeReader());

        self::assertSame(['/x'], $resolver->resolve(new GuardedPost(false), Event::Deleted));
    }

    public function testWhenTrueResolvesForNonDeleteEvents(): void
    {
        $inner = new CallableUrlResolver(static fn(): array => ['/x']);
        $resolver = new GuardedUrlResolver($inner, new AttributeReader());

        self::assertSame(['/x'], $resolver->resolve(new GuardedPost(true), Event::Updated));
    }

    public function testThrowingInnerResolverIsLoggedAndReturnsEmpty(): void
    {
        $logger = new ArrayLogger();
        $resolver = new GuardedUrlResolver(new ThrowingUrlResolver(), new AttributeReader(), $logger);

        $urls = $resolver->resolve(new GuardedPost(true), Event::Updated);

        self::assertSame([], $urls);
        self::assertCount(1, $logger->messages('error'));
    }
}
