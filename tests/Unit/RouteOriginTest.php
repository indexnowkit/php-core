<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Url\RouteOrigin;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the four router bridges decide the same way (spec 19 §4.6): the locale expansion with its one warning per
 * process, the origin of a pinned host, the rebase of a generated URL onto it, and the two exceptions.
 */
final class RouteOriginTest extends TestCase
{
    #[TestDox('expand(): a list as it is (empty = current), current, all = the configured list')]
    public function testExpand(): void
    {
        self::assertSame(['en', 'de'], RouteOrigin::expand(['en', 'de'], ['fr']));
        self::assertSame([null], RouteOrigin::expand([], ['fr']));
        self::assertSame([null], RouteOrigin::expand('current', ['fr']));
        self::assertSame(['fr', 'it'], RouteOrigin::expand('all', ['fr', 'it']));
        self::assertSame([null], RouteOrigin::expand('all', []), 'no logger: the current locale, silently');
    }

    #[TestDox('all with an empty list warns once per process through the adapter flag, naming the adapter option')]
    public function testExpandWarnsOnce(): void
    {
        $logger = new ArrayLogger();
        $warned = false;

        self::assertSame([null], RouteOrigin::expand('all', [], $logger, 'framework.enabled_locales', $warned));
        self::assertSame([null], RouteOrigin::expand('all', [], $logger, 'framework.enabled_locales', $warned));

        self::assertTrue($warned);
        self::assertSame(['indexnow: a rule asks for locales: \'all\' but "framework.enabled_locales" is empty; one URL in the current locale is generated instead of one per locale'], $logger->messages('warning'));

        $every = new ArrayLogger();
        RouteOrigin::expand('all', [], $every);
        RouteOrigin::expand('all', [], $every);
        self::assertCount(2, $every->messages('warning'), 'without a flag every call warns');
    }

    #[TestDox('pinnedRoot(): hosts.<host>.base_url, else https://<host>')]
    public function testPinnedRoot(): void
    {
        $config = Factory::config(['hosts' => ['shop.example.com' => ['key' => Factory::KEY, 'base_url' => 'http://shop.example.com:8080']]]);

        self::assertSame('http://shop.example.com:8080', RouteOrigin::pinnedRoot($config, 'shop.example.com'));
        self::assertSame('https://www.example.com', RouteOrigin::pinnedRoot($config, 'www.example.com'), 'base_url of the base host');
        self::assertSame('https://other.example.com', RouteOrigin::pinnedRoot($config, 'other.example.com'));
    }

    #[TestDox('rebase(): scheme, host and port of the root; path, query and fragment of the URL; a root without a host leaves the URL alone')]
    public function testRebase(): void
    {
        self::assertSame('https://www.example.com/posts/1?x=1#top', RouteOrigin::rebase('http://localhost:8000/posts/1?x=1#top', 'https://www.example.com'));
        self::assertSame('http://shop.example.com:8080/p', RouteOrigin::rebase('https://www.example.com/p', 'http://shop.example.com:8080/base/'));
        self::assertSame('https://www.example.com/', RouteOrigin::rebase('https://www.example.com', 'https://www.example.com'), 'no path: the root');
        self::assertSame('https://www.example.com/p', RouteOrigin::rebase('https://www.example.com/p', '/relative'));
        self::assertSame('https://www.example.com/p', RouteOrigin::rebase('https://www.example.com/p', 'not a url at all:'));
    }

    #[TestDox('the two exceptions carry the route and the cause, and the place the host was missing')]
    public function testExceptions(): void
    {
        $cause = new RuntimeException('no route named post');
        $failed = RouteOrigin::generationFailed('post', $cause);
        self::assertInstanceOf(ConfigurationException::class, $failed);
        self::assertSame('Cannot generate route "post": no route named post', $failed->getMessage());
        self::assertSame($cause, $failed->getPrevious());

        self::assertSame('No request to take the host from: set base_url to generate URLs in a console command.', RouteOrigin::noRequestHost()->getMessage());
        self::assertSame('No request to take the host from: set base_url to generate URLs in a console application.', RouteOrigin::noRequestHost('a console application')->getMessage());
    }
}
