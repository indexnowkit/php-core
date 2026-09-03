<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use DateTimeImmutable;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Dispatch\CallableDispatcher;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Result;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Url\CallableUrlResolver;
use IndexNowKit\Url\UrlNormalizerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

#[IndexNowAttribute(resolver: 'any', when: 'published')]
final class FacadePost
{
    public function __construct(public string $slug, public bool $published = true) {}
}

#[IndexNowAttribute(resolver: 'any', events: ['created'])]
final class FacadeCreatedOnlyPost {}

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

final class FacadeFakeSubmitter implements SubmitterInterface
{
    /** @return list<Result> */
    public function submit(iterable $urls): array
    {
        return [];
    }

    /** @return list<string> */
    public function prepare(iterable $urls): array
    {
        return [];
    }

    public function addListener(callable $listener): void {}
}

final class FacadeTest extends TestCase
{
    private static function facadePostSlugResolver(): CallableUrlResolver
    {
        return new CallableUrlResolver(static function (object $p, Event $e): string {
            self::assertInstanceOf(FacadePost::class, $p);

            return '/posts/' . $p->slug;
        });
    }

    public function testSubmitEntityUsesTheConfiguredResolver(): void
    {
        $t = new FakeTransport();
        $inx = IndexNowKit::create(Factory::config(), $t, resolver: self::facadePostSlugResolver());

        $inx->submitEntity(new FacadePost('a'));

        self::assertSame(['https://www.example.com/posts/a'], $t->posts[0]['body']['urlList']);
    }

    public function testCustomResolverIsUsedRegardlessOfWhenSinceGuardedUrlResolverNoLongerAppliesItItself(): void
    {
        $t = new FakeTransport();
        $inx = IndexNowKit::create(Factory::config(), $t, resolver: self::facadePostSlugResolver());

        $results = $inx->submitEntity(new FacadePost('draft', published: false));

        self::assertNotSame([], $results, '`when` is only enforced per rule inside AttributeUrlResolver, not by GuardedUrlResolver for a custom resolver');
        self::assertSame(['https://www.example.com/posts/draft'], $t->posts[0]['body']['urlList']);
    }

    public function testCustomResolverStillRespectsClassLevelEventSubscription(): void
    {
        $t = new FakeTransport();
        $inx = IndexNowKit::create(Factory::config(), $t, resolver: new CallableUrlResolver(static fn(): string => '/x'));

        $results = $inx->submitEntity(new FacadeCreatedOnlyPost(), Event::Updated);

        self::assertSame([], $results);
        self::assertCount(0, $t->posts);
    }

    public function testSitemapSourceDefaultsToAReaderOverTheSubmissionTransport(): void
    {
        $transport = new FakeTransport();
        $transport->onGet('https://www.example.com/sitemap.xml', new \IndexNowKit\Http\Response(200, "https://www.example.com/a\nhttps://www.example.com/b\n"));
        $kit = IndexNowKit::create(Factory::config(), transport: $transport);

        self::assertSame($transport, $kit->transport);
        self::assertInstanceOf(\IndexNowKit\Sitemap\SitemapReader::class, $kit->sitemap());
        self::assertSame($kit->sitemap(), $kit->sitemap(), 'built once');
        $urls = [];
        foreach ($kit->sitemap()->read('https://www.example.com/sitemap.xml') as $entry) {
            $urls[] = $entry->url;
        }
        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $urls, 'a text sitemap through the shared transport');

        $custom = new class implements \IndexNowKit\Sitemap\SitemapSourceInterface {
            public function read(string $sitemap, ?DateTimeImmutable $changedSince = null): iterable
            {
                yield new \IndexNowKit\Sitemap\SitemapEntry('https://www.example.com/custom', null);
            }
        };
        self::assertSame($custom, IndexNowKit::create(Factory::config(), transport: $transport, sitemap: $custom)->sitemap());
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

    public function testCreateRejectsASubmitterCombinedWithATransport(): void
    {
        $this->expectException(ConfigurationException::class);
        IndexNowKit::create(Factory::config(), transport: new FakeTransport(), submitter: new FacadeFakeSubmitter());
    }

    public function testCreateRejectsASubmitterCombinedWithADebounceStore(): void
    {
        $this->expectException(ConfigurationException::class);
        IndexNowKit::create(Factory::config(), debounce: new MemoryDebounceStore(), submitter: new FacadeFakeSubmitter());
    }

    public function testCreateAcceptsACustomSubmitterAlone(): void
    {
        $submitter = new FacadeFakeSubmitter();

        $inx = IndexNowKit::create(Factory::config(), submitter: $submitter);

        self::assertSame($submitter, $inx->submitter);
    }

    public function testExplainReturnsResolvedUrlsWithProvenanceFromTheConfiguredResolver(): void
    {
        $inx = IndexNowKit::create(Factory::config(), new FakeTransport(), resolver: new CallableUrlResolver(static fn(): string => '/explained'));

        $resolved = $inx->explain(new stdClass(), Event::Updated);

        self::assertCount(1, $resolved);
        self::assertSame('/explained', $resolved[0]->url);
        self::assertSame('custom', $resolved[0]->rule);
    }
}
