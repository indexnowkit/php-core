<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Event;
use IndexNowKit\Url\CallableUrlResolver;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CallableUrlResolverTest extends TestCase
{
    public function testNullResultBecomesEmptyArray(): void
    {
        $resolver = new CallableUrlResolver(static fn(): ?string => null);

        self::assertSame([], iterator_to_array($resolver->resolve(new stdClass(), Event::Updated)));
    }

    public function testStringResultIsWrappedInArray(): void
    {
        $resolver = new CallableUrlResolver(static fn(): string => '/a');

        self::assertSame(['/a'], iterator_to_array($resolver->resolve(new stdClass(), Event::Updated)));
    }

    public function testIterableResultPassesThrough(): void
    {
        $resolver = new CallableUrlResolver(static fn(): array => ['/a', '/b']);

        self::assertSame(['/a', '/b'], iterator_to_array($resolver->resolve(new stdClass(), Event::Updated)));
    }

    public function testCallableReceivesSubjectAndEvent(): void
    {
        $received = null;
        $resolver = new CallableUrlResolver(function (object $subject, Event $event) use (&$received): null {
            $received = [$subject, $event];

            return null;
        });
        $subject = new stdClass();

        $resolver->resolve($subject, Event::Deleted);

        self::assertSame([$subject, Event::Deleted], $received);
    }
}
