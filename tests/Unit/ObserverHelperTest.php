<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Collector\CollectorInterface;
use IndexNowKit\Hook\ObserverHelper;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\ObjectChangeHandler;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[IndexNow(url: 'url', when: 'published')]
final class ObserverHelperPost
{
    public function __construct(public string $slug, public bool $published = true) {}

    public function url(): string
    {
        return '/posts/' . $this->slug;
    }
}

/**
 * `Hook\ObserverHelper` (docs/spec/16 §4.2): the never-throwing part of every ORM observer.
 */
final class ObserverHelperTest extends TestCase
{
    private static function kit(FakeTransport $transport, ?ArrayLogger $logger = null, ?CollectorInterface $collector = null): IndexNowKit
    {
        $config = Factory::config();

        return IndexNowKit::create($config, transport: $transport, logger: $logger, resolver: AttributeUrlResolver::fromConfig($config, new AttributeReader()), collector: $collector);
    }

    #[TestDox('guard() resolves through the change handler, logs every URL at debug, and returns the de-duplicated list')]
    public function testGuard(): void
    {
        $logger = new ArrayLogger();
        $kit = self::kit(transport: new FakeTransport(), logger: $logger);
        $helper = new ObserverHelper($kit, $logger);
        $post = new ObserverHelperPost('hello');

        $urls = $helper->guard($post, static fn(ObjectChangeHandler $changes): array => [...$changes->updated($post, ['title']), ...$changes->updated($post, ['title'])]);
        self::assertSame(['/posts/hello'], $urls);
        self::assertSame(['indexnow: ' . ObserverHelperPost::class . '#url:url (updated) -> /posts/hello', 'indexnow: ' . ObserverHelperPost::class . '#url:url (updated) -> /posts/hello'], $logger->messages('debug'));
        self::assertSame([], $helper->guard(new ObserverHelperPost('draft', false), static fn(ObjectChangeHandler $changes): array => $changes->created(new ObserverHelperPost('draft', false))), 'a guarded-out object yields an empty list, not null');
    }

    #[TestDox('guard() never throws: a failing resolve is one error line and null')]
    public function testGuardNeverThrows(): void
    {
        $logger = new ArrayLogger();
        $helper = new ObserverHelper(self::kit(transport: new FakeTransport(), logger: $logger), $logger);

        self::assertNull($helper->guard(new ObserverHelperPost('x'), static fn(): array => throw new RuntimeException('relation not loaded')));
        self::assertSame(['indexnow: cannot resolve the URLs of ' . ObserverHelperPost::class . ': relation not loaded'], $logger->messages('error'));
    }

    #[TestDox('deliver() collects, and a failing collector is one error line; nothing is collected for an empty list')]
    public function testDeliver(): void
    {
        $logger = new ArrayLogger();
        $transport = new FakeTransport();
        $kit = self::kit(transport: $transport, logger: $logger);
        $helper = new ObserverHelper($kit, $logger);

        $helper->deliver([]);
        self::assertTrue($kit->collector->isEmpty());
        $helper->deliver(['/posts/a', '/posts/b']);
        $kit->flush();
        self::assertSame(['https://www.example.com/posts/a', 'https://www.example.com/posts/b'], $transport->posts[0]['body']['urlList']);

        $broken = self::kit(transport: $transport, logger: $logger, collector: new class implements CollectorInterface {
            public function add(iterable $urls): void
            {
                throw new RuntimeException('collector full');
            }

            public function isEmpty(): bool
            {
                return true;
            }

            public function count(): int
            {
                return 0;
            }

            public function all(): array
            {
                return [];
            }

            public function drain(): array
            {
                return [];
            }

            public function reset(): void {}
        });
        (new ObserverHelper($broken, $logger))->deliver(['/x']);
        self::assertSame(['indexnow: cannot collect 1 URL(s): collector full'], $logger->messages('error'));
    }

    #[TestDox('rememberDeletion()/takeDeletion() keep the URLs of a row between the before and after hooks, once, per object')]
    public function testDeletions(): void
    {
        $helper = new ObserverHelper(self::kit(transport: new FakeTransport()));
        $a = new ObserverHelperPost('a');
        $b = new ObserverHelperPost('b');

        self::assertNull($helper->takeDeletion($a), 'the before hook was not seen');
        $helper->rememberDeletion($a, ['/posts/a']);
        $helper->rememberDeletion($b, ['/posts/b']);
        self::assertSame(['/posts/a'], $helper->takeDeletion($a));
        self::assertNull($helper->takeDeletion($a), 'taken once');
        self::assertSame(['/posts/b'], $helper->takeDeletion($b));
    }
}
