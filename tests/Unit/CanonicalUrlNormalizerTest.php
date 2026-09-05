<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Url\CanonicalUrlNormalizer;
use IndexNowKit\Url\UrlNormalizer;
use IndexNowKit\Url\UrlNormalizerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class CanonicalUrlNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function strippedProvider(): iterable
    {
        yield 'utm_* prefix' => ['https://www.example.com/a?utm_source=x&utm_medium=y', 'https://www.example.com/a'];
        yield 'gclid in the middle keeps the others in order' => ['https://www.example.com/a?page=2&gclid=abc&sort=asc', 'https://www.example.com/a?page=2&sort=asc'];
        yield 'names are case-insensitive' => ['https://www.example.com/a?FBCLID=1&UTM_Campaign=2&id=7', 'https://www.example.com/a?id=7'];
        yield 'yandex and openstat' => ['https://www.example.com/a?yclid=1&ysclid=2&_openstat=3&etext=4', 'https://www.example.com/a'];
        yield 'a parameter without a value' => ['https://www.example.com/a?gclid&x', 'https://www.example.com/a?x'];
        yield 'an encoded name' => ['https://www.example.com/a?utm%5Fsource=x&q=1', 'https://www.example.com/a?q=1'];
        yield 'nothing to strip is untouched, encoding included' => ['https://www.example.com/a?q=a%20b&r=%C3%A9', 'https://www.example.com/a?q=a%20b&r=%C3%A9'];
        yield 'an empty query goes away' => ['https://www.example.com/a?', 'https://www.example.com/a'];
        yield 'the fragment is already gone, the port stays' => ['https://www.example.com:8443/a?utm_x=1#top', 'https://www.example.com:8443/a'];
        yield 'a relative URL goes through the inner normalizer first' => ['/a?utm_source=x', 'https://www.example.com/a'];
    }

    #[DataProvider('strippedProvider')]
    public function testTrackingParamsAreStripped(string $url, string $expected): void
    {
        $normalizer = new CanonicalUrlNormalizer(new UrlNormalizer('https://www.example.com'));

        self::assertSame($expected, $normalizer->normalize($url));
        self::assertSame('www.example.com', $normalizer->hostOf($normalizer->normalize($url)));
    }

    public function testExtraTrackingParamsAndTheListIsTheConstant(): void
    {
        $normalizer = new CanonicalUrlNormalizer(new UrlNormalizer(), trackingParams: ['ref', 'MTM_*']);

        self::assertSame('https://www.example.com/a?id=1', $normalizer->normalize('https://www.example.com/a?ref=tw&mtm_campaign=x&id=1&Mtm_Kwd=y'));
        self::assertTrue($normalizer->isTrackingParam('ref'));
        self::assertTrue($normalizer->isTrackingParam('utm_anything'));
        self::assertFalse($normalizer->isTrackingParam('reference'), 'an exact name is not a prefix');
        self::assertContains('utm_*', CanonicalUrlNormalizer::TRACKING_PARAMS);
        self::assertContains('_ga', CanonicalUrlNormalizer::TRACKING_PARAMS);
    }

    public function testStrippingCanBeTurnedOff(): void
    {
        $normalizer = new CanonicalUrlNormalizer(new UrlNormalizer(), stripTrackingParams: false);

        self::assertSame('https://www.example.com/a?utm_source=x', $normalizer->normalize('https://www.example.com/a?utm_source=x'));
        self::assertSame('https://www.example.com/a?', $normalizer->normalize('https://www.example.com/a?'), 'nothing is touched at all');
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function trailingSlashProvider(): iterable
    {
        yield 'keep leaves both forms' => ['keep', 'https://www.example.com/blog/hello', 'https://www.example.com/blog/hello'];
        yield 'keep leaves the slash' => ['keep', 'https://www.example.com/blog/hello/', 'https://www.example.com/blog/hello/'];
        yield 'add appends to a page path' => ['add', 'https://www.example.com/blog/hello?x=1', 'https://www.example.com/blog/hello/?x=1'];
        yield 'add leaves a file alone' => ['add', 'https://www.example.com/sitemap.xml', 'https://www.example.com/sitemap.xml'];
        yield 'add leaves the root alone' => ['add', 'https://www.example.com/', 'https://www.example.com/'];
        yield 'add does not double' => ['add', 'https://www.example.com/blog/', 'https://www.example.com/blog/'];
        yield 'strip removes it' => ['strip', 'https://www.example.com/blog/hello/?x=1', 'https://www.example.com/blog/hello?x=1'];
        yield 'strip removes several' => ['strip', 'https://www.example.com/blog//', 'https://www.example.com/blog'];
        yield 'strip keeps the root' => ['strip', 'https://www.example.com/', 'https://www.example.com/'];
    }

    #[DataProvider('trailingSlashProvider')]
    public function testTrailingSlashPolicy(string $mode, string $url, string $expected): void
    {
        $normalizer = new CanonicalUrlNormalizer(new UrlNormalizer(), trailingSlash: $mode);

        self::assertSame($expected, $normalizer->normalize($url));
    }

    public function testSortQueryIsStableAndByName(): void
    {
        $normalizer = new CanonicalUrlNormalizer(new UrlNormalizer(), sortQuery: true);

        self::assertSame('https://www.example.com/a?a=2&b=1&b=0&c', $normalizer->normalize('https://www.example.com/a?b=1&c&a=2&b=0'));
        self::assertSame('https://www.example.com/a?a=2&b=1', $normalizer->normalize('https://www.example.com/a?b=1&utm_source=x&a=2'), 'stripping and sorting combine');
    }

    #[TestDox('UrlNormalizerFactory::fromConfig(): the plain normalizer only when every option is off; the canonical one by default (strip_tracking_params) and for every other option')]
    public function testFactory(): void
    {
        self::assertInstanceOf(CanonicalUrlNormalizer::class, UrlNormalizerFactory::fromConfig(Factory::config()));
        self::assertInstanceOf(UrlNormalizer::class, UrlNormalizerFactory::fromConfig(Factory::config(['normalizer' => ['strip_tracking_params' => false]])));
        self::assertInstanceOf(CanonicalUrlNormalizer::class, UrlNormalizerFactory::fromConfig(Factory::config(['normalizer' => ['strip_tracking_params' => false, 'trailing_slash' => 'add']])));
        self::assertInstanceOf(CanonicalUrlNormalizer::class, UrlNormalizerFactory::fromConfig(Factory::config(['normalizer' => ['strip_tracking_params' => false, 'sort_query' => true]])));

        $config = Factory::config(['normalizer' => ['tracking_params' => ['ref', 'MTM_*'], 'trailing_slash' => 'strip', 'sort_query' => true], 'max_url_length' => 100]);
        self::assertTrue($config->normalizerStripTrackingParams);
        self::assertSame(['ref', 'mtm_*'], $config->normalizerTrackingParams);
        self::assertSame('strip', $config->normalizerTrailingSlash);
        self::assertTrue($config->normalizerSortQuery);
        $normalizer = UrlNormalizerFactory::fromConfig($config);
        self::assertSame('https://www.example.com/blog?a=1&z=2', $normalizer->normalize('/blog/?z=2&ref=tw&a=1&mtm_c=x'));
        self::assertSame(['ref', 'mtm_*'], $config->with(engines: ['yandex'])->normalizerTrackingParams, 'with() carries the block');
        self::assertFalse($config->with(normalizerStripTrackingParams: false)->normalizerStripTrackingParams);
        self::assertSame('https://www.example.com/blog?z=2&ref=tw&a=1&mtm_c=x', UrlNormalizerFactory::fromConfig(Factory::config(['normalizer' => ['strip_tracking_params' => false, 'trailing_slash' => 'strip']]))->normalize('/blog/?z=2&ref=tw&a=1&mtm_c=x'));
        self::assertSame(['a' => 'x'], array_filter(['a' => 'x', 'tracking_params' => Config::OPTIONS], static fn($v): bool => $v === 'x'), 'sanity');
        foreach (['normalizer.strip_tracking_params', 'normalizer.tracking_params', 'normalizer.trailing_slash', 'normalizer.sort_query'] as $key) {
            self::assertContains($key, Config::OPTIONS);
        }
        self::assertSame([], Config::unknownOptions(['normalizer' => ['strip_tracking_params' => false]]));
        self::assertSame(['normalizer.strip_tracking'], Config::unknownOptions(['normalizer' => ['strip_tracking' => false]]));
    }

    public function testTrailingSlashIsValidated(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"normalizer.trailing_slash" must be one of keep, add, strip, got "always"');
        Factory::config(['normalizer' => ['trailing_slash' => 'always']]);
    }

    public function testTrackingParamsAreValidated(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"normalizer.tracking_params" must list query parameter names');
        Factory::config(['normalizer' => ['tracking_params' => ['a b']]]);
    }

    public function testTheSubmitterCanonicalizesBeforeDedupAndDebounce(): void
    {
        $t = new \IndexNowKit\Testing\FakeTransport();
        $config = Factory::config(['debounce' => ['per_url' => 600]]);
        $submitter = Factory::submitter($t, $config);

        $results = $submitter->submit(['/a?utm_source=x', '/a?utm_source=y', '/a']);
        self::assertCount(1, $results);
        self::assertSame(['https://www.example.com/a'], $results[0]->urls, 'three tracked variants are one URL');

        $again = $submitter->submit(['/a?gclid=1']);
        self::assertSame(\IndexNowKit\Reason::Debounced, $again[0]->reason, 'the debounce window is keyed by the canonical URL');
    }
}
