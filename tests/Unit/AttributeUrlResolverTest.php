<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\CallableUrlResolver;
use IndexNowKit\Url\RouteUrlResolverInterface;
use PHPUnit\Framework\TestCase;

#[IndexNowAttribute(route: 'post_show', params: ['slug' => 'slug'])]
final class RoutedPost
{
    public function __construct(public string $slug) {}
}

#[IndexNowAttribute(resolver: 'custom')]
final class ResolverBackedPost {}

final class NotAnnotatedForResolver {}

final class StubRouter implements RouteUrlResolverInterface
{
    /** @var list<array{route: string, params: array<string, mixed>, locales: array<string>|string}> */
    public array $calls = [];

    public function generate(string $route, array $params, array|string $locales): iterable
    {
        $this->calls[] = ['route' => $route, 'params' => $params, 'locales' => $locales];

        return ['https://example.com/' . ($params['slug'] ?? '')];
    }
}

final class AttributeUrlResolverTest extends TestCase
{
    public function testNoAttributeReturnsEmpty(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());

        self::assertSame([], iterator_to_array($resolver->resolve(new NotAnnotatedForResolver(), Event::Updated)));
    }

    public function testResolverIdIsLookedUpThroughLocator(): void
    {
        $inner = new CallableUrlResolver(static fn(): array => ['https://example.com/x']);
        $locator = new ArrayResolverLocator(['custom' => $inner]);
        $resolver = new AttributeUrlResolver(new AttributeReader(), null, $locator);

        $urls = iterator_to_array($resolver->resolve(new ResolverBackedPost(), Event::Updated));

        self::assertSame(['https://example.com/x'], $urls);
    }

    public function testResolverWithoutLocatorConfiguredThrows(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());

        $this->expectException(ConfigurationException::class);
        iterator_to_array($resolver->resolve(new ResolverBackedPost(), Event::Updated));
    }

    public function testRouteWithoutRouterConfiguredThrows(): void
    {
        $resolver = new AttributeUrlResolver(new AttributeReader());

        $this->expectException(ConfigurationException::class);
        iterator_to_array($resolver->resolve(new RoutedPost('hello'), Event::Updated));
    }

    public function testRouteDelegatesToRouterWithExtractedParamsAndLocales(): void
    {
        $router = new StubRouter();
        $resolver = new AttributeUrlResolver(new AttributeReader(), $router);

        $urls = iterator_to_array($resolver->resolve(new RoutedPost('hello'), Event::Updated));

        self::assertSame(['https://example.com/hello'], $urls);
        self::assertSame('post_show', $router->calls[0]['route']);
        self::assertSame(['slug' => 'hello'], $router->calls[0]['params']);
        self::assertSame('current', $router->calls[0]['locales']);
    }
}
