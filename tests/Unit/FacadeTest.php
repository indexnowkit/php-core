<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Dispatch\CallableDispatcher;
use IndexNowKit\Event;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Url\CallableUrlResolver;
use IndexNowKit\Url\UrlNormalizerInterface;
use PHPUnit\Framework\TestCase;

#[IndexNowAttribute(resolver: 'any', when: 'published')]
final class FacadePost
{
    public function __construct(public string $slug, public bool $published = true) {}
}

#[IndexNowAttribute(route: 'x', when: 'badAccessor')]
final class BadWhenPost {}

final class RecordingThrottle implements ThrottleInterface
{
    public int $calls = 0;

    public function acquire(): void
    {
        ++$this->calls;
    }
}

final class MappingNormalizer implements UrlNormalizerInterface
{
    public function normalize(string $url): string
    {
        return 'https://custom.example.com/mapped';
    }

    public function hostOf(string $normalizedUrl): string
    {
        return 'custom.example.com';
    }
}

final class FacadeTest extends TestCase
{
    public function testSubmitEntityUsesResolverAndGuard(): void
    {
        $t = new FakeTransport();
        $inx = IndexNowKit::create(Factory::config(), $t, resolver: new CallableUrlResolver(static fn(FacadePost $p, Event $e) => '/posts/' . $p->slug));

        $inx->submitEntity(new FacadePost('a'));
        self::assertSame(['https://www.example.com/posts/a'], $t->posts[0]['body']['urlList']);

        self::assertSame([], $inx->submitEntity(new FacadePost('draft', published: false)));
        self::assertCount(1, $t->posts);

        // deletions are sent even when unpublished
        $inx->submitEntity(new FacadePost('gone', published: false), Event::Deleted);
        self::assertCount(2, $t->posts);
    }

    public function testCollectAndFlushGoThroughDispatcher(): void
    {
        $received = [];
        $inx = IndexNowKit::create(Factory::config(), new FakeTransport(), dispatcher: new CallableDispatcher(static function (array $urls) use (&$received): void {
            $received = $urls;
        }));

        $inx->collect(['/a', '/a', '/b']);
        $inx->flush();
        $inx->flush();

        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $received);
    }

    public function testFlushOnEmptyCollectorDoesNothing(): void
    {
        $called = false;
        $inx = IndexNowKit::create(Factory::config(), new FakeTransport(), dispatcher: new CallableDispatcher(static function () use (&$called): void {
            $called = true;
        }));

        $inx->flush();

        self::assertFalse($called);
    }

    public function testUrlsForNeverThrowsWhenTheWhenAccessorIsMissing(): void
    {
        $logger = new ArrayLogger();
        $inx = IndexNowKit::create(Factory::config(), new FakeTransport(), logger: $logger);

        $urls = $inx->urlsFor(new BadWhenPost(), Event::Updated);

        self::assertSame([], $urls);
        self::assertCount(1, $logger->messages('error'));
    }

    public function testCreateAcceptsCustomKeysThrottleNormalizerAndAttributeReader(): void
    {
        $t = new FakeTransport();
        $keys = new StaticKeyProvider('customkey1234', ['custom.example.com' => 'hostkey12345']);
        $throttle = new RecordingThrottle();
        $normalizer = new MappingNormalizer();
        $attributes = new AttributeReader();

        $inx = IndexNowKit::create(Factory::config(), $t, keys: $keys, throttle: $throttle, normalizer: $normalizer, attributes: $attributes);
        $inx->submit(['/whatever']);

        self::assertSame(['host' => 'custom.example.com', 'key' => 'hostkey12345', 'urlList' => ['https://custom.example.com/mapped']], $t->posts[0]['body']);
        self::assertSame(1, $throttle->calls);
        self::assertSame($attributes, $inx->attributes);
        self::assertSame($keys, $inx->keys);
    }
}
