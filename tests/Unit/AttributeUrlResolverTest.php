<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Attribute\Param\Accessor;
use IndexNowKit\Attribute\Param\Call;
use IndexNowKit\Attribute\Param\Placeholder;
use IndexNowKit\Attribute\Param\Value;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\CallableUrlResolver;
use IndexNowKit\Url\RouteUrlResolverInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

final class StubRouter implements RouteUrlResolverInterface
{
    /** @var list<array{route: string, params: array<string, mixed>, locale: ?string, host: ?string}> */
    public array $calls = [];

    /**
     * @param list<string|null> $allLocales
     */
    public function __construct(private array $allLocales = [null], private ?string $currentLocale = null) {}

    public function locales(array|string $locales): array
    {
        if ($locales === 'all') {
            return $this->allLocales;
        }
        if ($locales === 'current') {
            return [$this->currentLocale];
        }

        return \is_array($locales) ? array_values($locales) : [$locales];
    }

    public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string
    {
        $this->calls[] = ['route' => $route, 'params' => $params, 'locale' => $locale, 'host' => $host];
        $slugValue = $params['slug'] ?? '';
        $slug = \is_scalar($slugValue) ? (string) $slugValue : '';
        $prefix = $locale !== null ? $locale . '/' : '';

        return 'https://' . ($host ?? 'example.com') . '/' . $prefix . $route . '/' . $slug;
    }
}

#[IndexNowAttribute(route: 'post_show', params: ['slug' => 'slug'])]
final class RoutedPost
{
    public function __construct(public string $slug) {}
}

#[IndexNowAttribute(resolver: 'custom')]
final class ResolverBackedPost {}

final class NotAnnotatedForResolver {}

#[IndexNowAttribute(route: 'post_show', params: ['slug' => 'slug'])]
#[IndexNowAttribute(route: 'post_amp', params: ['slug' => 'slug'], when: 'amp', name: 'amp')]
final class MultiRoutePost
{
    public function __construct(public string $slug, public bool $amp = false) {}
}

#[IndexNowAttribute(urls: ['/sitemap.xml', 'https://static.example.com/robots.txt'])]
final class LiteralUrlsPost {}

#[IndexNowAttribute(url: 'publicUrl')]
final class UrlAccessorPost
{
    public mixed $urlValue = null;

    public function getPublicUrl(): mixed
    {
        return $this->urlValue;
    }
}

#[IndexNowAttribute(route: 'category_show', params: ['slug' => 'slug'])]
final class ViaCategory
{
    public function __construct(public string $slug) {}
}

#[IndexNowAttribute(via: 'category')]
final class ViaCommentSingle
{
    public function __construct(public ViaCategory $category) {}
}

#[IndexNowAttribute(via: 'categories')]
final class ViaCommentCollection
{
    /** @param list<ViaCategory> $categories */
    public function __construct(public array $categories) {}
}

#[IndexNowAttribute(via: 'toB')]
final class LoopA
{
    public ?LoopB $toB = null;
}

#[IndexNowAttribute(via: 'toA')]
final class LoopB
{
    public ?LoopA $toA = null;
}

#[IndexNowAttribute(via: 'friends')]
final class FanoutHub
{
    /** @param list<object> $friends */
    public function __construct(public array $friends) {}
}

#[IndexNowAttribute(route: 'post_show', params: ['slug' => 'slug'], host: new Value('cdn.example.com'))]
final class HostLiteralPost
{
    public function __construct(public string $slug) {}
}

#[IndexNowAttribute(route: 'post_show', params: ['slug' => 'slug'], host: new Accessor('domain'))]
final class HostAccessorPost
{
    public function __construct(public string $slug, public string $domain) {}
}

#[IndexNowAttribute(route: 'post_show', params: ['slug' => new Call('slugFor', Placeholder::Locale)], locales: 'all')]
final class LocalizedPost
{
    public function slugFor(?string $locale): string
    {
        return 'slug-' . ($locale ?? 'none');
    }
}

#[IndexNowAttribute(route: 'post_show', params: ['slug' => 'slug'], when: 'published')]
final class DeletedBypassPost
{
    public function __construct(public string $slug, public bool $published = false) {}
}

final class AttributeUrlResolverTest extends TestCase
{
    public function testNoAttributeReturnsEmpty(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());

        self::assertSame([], $resolver->resolve(new NotAnnotatedForResolver(), Event::Updated));
    }

    public function testResolverIdIsLookedUpThroughLocator(): void
    {
        $inner = new CallableUrlResolver(static fn(): array => ['https://example.com/x']);
        $locator = new ArrayResolverLocator(['custom' => $inner]);
        $resolver = new AttributeUrlResolver(new AttributeReader(), null, $locator);

        self::assertSame(['https://example.com/x'], $resolver->resolve(new ResolverBackedPost(), Event::Updated));
    }

    public function testResolverWithoutLocatorConfiguredThrows(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());

        $this->expectException(ConfigurationException::class);
        $resolver->resolve(new ResolverBackedPost(), Event::Updated);
    }

    public function testRouteWithoutRouterConfiguredThrowsMentioningTheRuleName(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());

        try {
            $resolver->resolve(new RoutedPost('hello'), Event::Updated);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('rule "post_show"', $e->getMessage());
        }
    }

    public function testMissingResolverLocatorConfiguredThrowsMentioningTheRuleName(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());

        try {
            $resolver->resolve(new ResolverBackedPost(), Event::Updated);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('rule "resolver:custom"', $e->getMessage());
        }
    }

    public function testRouteDelegatesToRouterWithExtractedParamsAndLocales(): void
    {
        $router = new StubRouter();
        $resolver = new AttributeUrlResolver(new AttributeReader(), $router);

        $urls = $resolver->resolve(new RoutedPost('hello'), Event::Updated);

        self::assertSame(['https://example.com/post_show/hello'], $urls);
        self::assertSame('post_show', $router->calls[0]['route']);
        self::assertSame(['slug' => 'hello'], $router->calls[0]['params']);
    }

    public function testSeveralRouteRulesAreAllResolvedAndThePerRuleWhenGuardsEachOne(): void
    {
        $router = new StubRouter();
        $resolver = new AttributeUrlResolver(new AttributeReader(), $router);

        $notAmp = $resolver->explain(new MultiRoutePost('hello', amp: false), Event::Updated);
        self::assertCount(1, $notAmp);
        self::assertSame('post_show', $notAmp[0]->rule);

        $withAmp = $resolver->explain(new MultiRoutePost('hello', amp: true), Event::Updated);
        self::assertCount(2, $withAmp);
        self::assertSame(['post_show', 'amp'], array_map(static fn($r) => $r->rule, $withAmp));
    }

    public function testLiteralUrlsAreReturnedAsIs(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());

        $urls = $resolver->resolve(new LiteralUrlsPost(), Event::Updated);

        self::assertSame(['/sitemap.xml', 'https://static.example.com/robots.txt'], $urls);
    }

    public function testUrlAccessorReturningAStringYieldsOneUrl(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());
        $subject = new UrlAccessorPost();
        $subject->urlValue = '/offers/x';

        self::assertSame(['/offers/x'], $resolver->resolve($subject, Event::Updated));
    }

    public function testUrlAccessorReturningAnIterableYieldsEveryUrl(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());
        $subject = new UrlAccessorPost();
        $subject->urlValue = ['/a', '/b'];

        self::assertSame(['/a', '/b'], $resolver->resolve($subject, Event::Updated));
    }

    public function testUrlAccessorReturningNullYieldsNothing(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());
        $subject = new UrlAccessorPost();
        $subject->urlValue = null;

        self::assertSame([], $resolver->resolve($subject, Event::Updated));
    }

    public function testUrlAccessorReturningANonStringThrows(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());
        $subject = new UrlAccessorPost();
        $subject->urlValue = 42;

        $this->expectException(ConfigurationException::class);
        $resolver->resolve($subject, Event::Updated);
    }

    public function testViaToASingleRelatedObjectResubmitsItsPageAsUpdated(): void
    {
        $router = new StubRouter();
        $resolver = new AttributeUrlResolver(new AttributeReader(), $router);
        $subject = new ViaCommentSingle(new ViaCategory('news'));

        $resolved = $resolver->explain($subject, Event::Created);

        self::assertCount(1, $resolved);
        self::assertSame('via:category -> category_show', $resolved[0]->rule);
        self::assertSame(Event::Updated, $resolved[0]->event);
        self::assertSame('https://example.com/category_show/news', $resolved[0]->url);
    }

    public function testViaToACollectionResubmitsEveryRelatedObject(): void
    {
        $router = new StubRouter();
        $resolver = new AttributeUrlResolver(new AttributeReader(), $router);
        $subject = new ViaCommentCollection([new ViaCategory('news'), new ViaCategory('sports')]);

        $urls = $resolver->resolve($subject, Event::Created);

        self::assertSame(['https://example.com/category_show/news', 'https://example.com/category_show/sports'], $urls);
    }

    public function testViaExceedingTheMaximumDepthThrows(): void
    {
        $a = new LoopA();
        $b = new LoopB();
        $a->toB = $b;
        $b->toA = $a;
        $resolver = new AttributeUrlResolver(new AttributeReader());

        $this->expectException(ConfigurationException::class);
        $resolver->resolve($a, Event::Updated);
    }

    public function testViaFanoutBeyondTheLimitLogsAWarningAndStops(): void
    {
        $logger = new ArrayLogger();
        $resolver = new AttributeUrlResolver(new AttributeReader(), null, null, $logger, maxViaFanout: 2);
        $subject = new FanoutHub([new stdClass(), new stdClass(), new stdClass(), new stdClass()]);

        $resolver->resolve($subject, Event::Updated);

        $warnings = $logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('stops after 2 related objects', $warnings[0]);
    }

    public function testHostLiteralIsPassedToTheRouter(): void
    {
        $router = new StubRouter();
        $resolver = new AttributeUrlResolver(new AttributeReader(), $router);

        $resolver->resolve(new HostLiteralPost('hello'), Event::Updated);

        self::assertSame('cdn.example.com', $router->calls[0]['host']);
    }

    public function testHostAccessorIsResolvedFromTheSubjectAndPassedToTheRouter(): void
    {
        $router = new StubRouter();
        $resolver = new AttributeUrlResolver(new AttributeReader(), $router);

        $resolver->resolve(new HostAccessorPost('hello', 'tenant.example.com'), Event::Updated);

        self::assertSame('tenant.example.com', $router->calls[0]['host']);
    }

    public function testLocalesAllReExtractsParamsPerLocaleUsingTheCallPlaceholder(): void
    {
        $router = new StubRouter(allLocales: ['en', 'fr']);
        $resolver = new AttributeUrlResolver(new AttributeReader(), $router);

        $urls = $resolver->resolve(new LocalizedPost(), Event::Updated);

        self::assertSame(['https://example.com/en/post_show/slug-en', 'https://example.com/fr/post_show/slug-fr'], $urls);
        self::assertSame('en', $router->calls[0]['locale']);
        self::assertSame('fr', $router->calls[1]['locale']);
    }

    public function testResolveRuleBypassesWhenForDeletedButNotForUpdated(): void
    {
        $reader = new AttributeReader();
        $resolver = new AttributeUrlResolver($reader, new StubRouter());
        $subject = new DeletedBypassPost('gone', published: false);
        $rule = $reader->rules(DeletedBypassPost::class)->get('post_show');
        self::assertNotNull($rule);

        $deleted = $resolver->resolveRule($subject, $rule, Event::Deleted);
        self::assertSame(['https://example.com/post_show/gone'], array_map(static fn($r) => $r->url, $deleted), '`when` is false, but Deleted still resolves: the page just stopped applying and its URL must be sent so engines recrawl it');

        $updated = $resolver->resolveRule($subject, $rule, Event::Updated);
        self::assertSame([], $updated, '`when` is false: Created/Updated still require appliesTo()');
    }

    public function testExplainKeepsFullProvenance(): void
    {
        $router = new StubRouter(currentLocale: 'en');
        $resolver = new AttributeUrlResolver(new AttributeReader(), $router);

        $resolved = $resolver->explain(new RoutedPost('hello'), Event::Updated);

        self::assertCount(1, $resolved);
        self::assertSame('https://example.com/en/post_show/hello', $resolved[0]->url);
        self::assertSame('post_show', $resolved[0]->rule);
        self::assertSame(RoutedPost::class, $resolved[0]->class);
        self::assertSame(Event::Updated, $resolved[0]->event);
        self::assertSame('en', $resolved[0]->locale);
    }
}
