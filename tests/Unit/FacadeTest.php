<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Dispatch\CallableDispatcher;
use IndexNowKit\Event;
use IndexNowKit\IndexNow;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Tests\Support\FakeTransport;
use IndexNowKit\Url\CallableUrlResolver;
use PHPUnit\Framework\TestCase;

#[IndexNowAttribute(resolver: 'any', when: 'published')]
final class FacadePost
{
    public function __construct(public string $slug, public bool $published = true) {}
}

final class FacadeTest extends TestCase
{
    public function testSubmitEntityUsesResolverAndGuard(): void
    {
        $t = new FakeTransport();
        $inx = IndexNow::create(Factory::config(), $t, resolver: new CallableUrlResolver(static fn(FacadePost $p, Event $e) => '/posts/' . $p->slug));

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
        $inx = IndexNow::create(Factory::config(), new FakeTransport(), dispatcher: new CallableDispatcher(static function (array $urls) use (&$received): void {
            $received = $urls;
        }));

        $inx->collect(['/a', '/a', '/b']);
        $inx->flush();
        $inx->flush();

        self::assertSame(['https://www.example.com/a', 'https://www.example.com/b'], $received);
    }
}
