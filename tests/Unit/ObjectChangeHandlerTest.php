<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow as IndexNowAttribute;
use IndexNowKit\Attribute\RuleEvent;
use IndexNowKit\Event;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\ObjectChangeHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[IndexNowAttribute(urls: ['/x'], when: 'isPublished', fields: ['title'])]
final class ObjectChangeHandlerPost
{
    public function __construct(public bool $published = true, public string $title = 'x') {}

    public function isPublished(): bool
    {
        return $this->published;
    }
}

#[IndexNowAttribute]
final class ObjectChangeHandlerInvalidPost {}

#[IndexNowAttribute(route: 'nope')]
final class ObjectChangeHandlerRoutedPost {}

#[IndexNowAttribute(route: 'post_show', params: ['slug' => 'slug', 'cat' => 'category.slug'], when: 'published')]
final class ObjectChangeHandlerSluggedPost
{
    public function __construct(public string $slug, public bool $published = true, public ?ObjectChangeHandlerCategory $category = null) {}
}

#[IndexNowAttribute(route: 'post_show', params: ['slug' => 'slug'])]
final class ObjectChangeHandlerFrozenPost
{
    public function __construct(public readonly string $slug) {}
}

#[IndexNowAttribute(route: 'post_show', params: ['slug' => 'slug'])]
final class ObjectChangeHandlerLazyPost
{
    public string $slug;
}

final class ObjectChangeHandlerCategory
{
    public function __construct(public string $slug) {}
}

final class ObjectChangeHandlerRouter implements \IndexNowKit\Url\RouteUrlResolverInterface
{
    public function locales(array|string $locales): array
    {
        return [null];
    }

    public function generate(string $route, array $params, ?string $locale = null, ?string $host = null): string
    {
        return 'https://www.example.com/' . $route . '/' . implode('/', array_map('strval', $params));
    }
}

final class ObjectChangeHandlerTest extends TestCase
{
    private static function handler(?ArrayLogger $logger = null): ObjectChangeHandler
    {
        $logger ??= new ArrayLogger();
        $reader = new AttributeReader();
        $guarded = new GuardedUrlResolver(new AttributeUrlResolver($reader), $reader, $logger);

        return new ObjectChangeHandler($reader, $guarded, $logger);
    }

    public function testRenamedYieldsTheOldUrlsOfRouteRulesWhoseParamsChanged(): void
    {
        $reader = new AttributeReader();
        $logger = new ArrayLogger();
        $handler = new ObjectChangeHandler($reader, new GuardedUrlResolver(new AttributeUrlResolver($reader, new ObjectChangeHandlerRouter()), $reader, $logger), $logger);
        $post = new ObjectChangeHandlerSluggedPost('new-slug', category: new ObjectChangeHandlerCategory('tech'));

        $old = $handler->renamed($post, ['slug' => ['old-slug', 'new-slug'], 'title' => ['a', 'b']]);

        self::assertCount(1, $old);
        self::assertSame('https://www.example.com/post_show/old-slug/tech', $old[0]->url);
        self::assertSame(Event::Deleted, $old[0]->event);
        self::assertSame('new-slug', $post->slug, 'the object is restored');

        self::assertSame([], $handler->renamed($post, ['title' => ['a', 'b']]), 'a field no route parameter reads: nothing');
        self::assertSame([], $handler->renamed($post, ['slug' => ['new-slug', 'new-slug']]), 'same URL before and after: nothing');
        self::assertSame([], $handler->renamed($post, ['category' => [new ObjectChangeHandlerCategory('tech'), $post->category]]), 'dotted path root matched, URL unchanged');
        self::assertCount(1, $handler->renamed($post, ['category' => [new ObjectChangeHandlerCategory('news'), $post->category]]), 'a changed relation the path goes through');

        $draft = new ObjectChangeHandlerSluggedPost('new', published: false, category: new ObjectChangeHandlerCategory('tech'));
        self::assertSame([], $handler->renamed($draft, ['slug' => ['old', 'new']]), 'the old state was not public: no page to delete');
        self::assertCount(1, $handler->renamed($draft, ['slug' => ['old', 'new'], 'published' => [true, false]]), 'renamed and unpublished at once: the old public URL goes');
    }

    public function testRenamedSkipsObjectsWhosePreviousStateCannotBeRebuilt(): void
    {
        $reader = new AttributeReader();
        $logger = new ArrayLogger();
        $handler = new ObjectChangeHandler($reader, new GuardedUrlResolver(new AttributeUrlResolver($reader, new ObjectChangeHandlerRouter()), $reader, $logger), $logger);
        $post = new ObjectChangeHandlerSluggedPost('new', category: new ObjectChangeHandlerCategory('tech'));

        self::assertCount(1, $handler->renamed($post, ['slug' => ['old', 'new'], 'not_a_property' => [1, 2]]), 'a change-set entry that is not a property is ignored when the URL does not read it');

        $frozen = new ObjectChangeHandlerFrozenPost('new');
        self::assertSame([], $handler->renamed($frozen, ['slug' => ['old', 'new']]));
        self::assertStringContainsString('cannot rebuild', implode("\n", $logger->messages('debug')));
    }

    public function testRenamedNeverThrowsWhenThePreviousStateCannotBeApplied(): void
    {
        $reader = new AttributeReader();
        $logger = new ArrayLogger();
        $handler = new ObjectChangeHandler($reader, new GuardedUrlResolver(new AttributeUrlResolver($reader, new ObjectChangeHandlerRouter()), $reader, $logger), $logger);

        $lazy = (new ReflectionClass(ObjectChangeHandlerLazyPost::class))->newInstanceWithoutConstructor();
        self::assertSame([], $handler->renamed($lazy, ['slug' => ['old', 'new']]), 'an uninitialized typed property cannot be restored: skipped, not a TypeError in flush');
        self::assertStringContainsString('cannot rebuild', implode("\n", $logger->messages('debug')));

        $post = new ObjectChangeHandlerSluggedPost('new', category: new ObjectChangeHandlerCategory('tech'));
        self::assertSame([], $handler->renamed($post, ['slug' => ['old', 'new'], 'category' => ['tech', $post->category]]), 'a previous value the property type rejects');
        self::assertStringContainsString('cannot resolve the previous URLs', implode("\n", $logger->messages('error')));
        self::assertSame('new', $post->slug, 'fields changed before the failure are restored');
        self::assertSame('tech', $post->category->slug);
    }

    public function testCreatedEventsReturnsOneRuleEventWhenTheRuleApplies(): void
    {
        $events = self::handler()->createdEvents(new ObjectChangeHandlerPost(published: true));

        self::assertCount(1, $events);
        self::assertSame(Event::Created, $events[0]->event);
    }

    public function testCreatedEventsSkipsARuleWhenWhenIsFalseAndLogsDebug(): void
    {
        $logger = new ArrayLogger();

        $events = self::handler($logger)->createdEvents(new ObjectChangeHandlerPost(published: false));

        self::assertSame([], $events);
        self::assertStringContainsString('`when` is false', implode("\n", $logger->messages('debug')));
    }

    public function testDeletedEventsReturnsOneRuleEventWhenTheRuleApplies(): void
    {
        $events = self::handler()->deletedEvents(new ObjectChangeHandlerPost(published: true));

        self::assertCount(1, $events);
        self::assertSame(Event::Deleted, $events[0]->event);
    }

    public function testUpdatedEventsClassifiesPerRule(): void
    {
        $events = self::handler()->updatedEvents(new ObjectChangeHandlerPost(published: true, title: 'new'), ['title']);

        self::assertCount(1, $events);
        self::assertSame(Event::Updated, $events[0]->event);
    }

    public function testUpdatedEventsWithNoMatchingFieldYieldsNoEvents(): void
    {
        $events = self::handler()->updatedEvents(new ObjectChangeHandlerPost(), ['views']);

        self::assertSame([], $events);
    }

    public function testInvalidAttributeIsLoggedAsErrorAndYieldsNoEvents(): void
    {
        $logger = new ArrayLogger();
        $handler = self::handler($logger);

        $events = $handler->createdEvents(new ObjectChangeHandlerInvalidPost());

        self::assertSame([], $events);
        $errors = $logger->messages('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('invalid #[IndexNow]', $errors[0]);
    }

    public function testRulesOfReturnsAnEmptyRuleSetForAnInvalidAttribute(): void
    {
        $ruleSet = self::handler()->rulesOf(new ObjectChangeHandlerInvalidPost());

        self::assertTrue($ruleSet->isEmpty());
    }

    public function testResolveNeverThrowsEvenWhenTheRuleCannotBeResolved(): void
    {
        $logger = new ArrayLogger();
        $handler = self::handler($logger);
        // A route-sourced rule with no router configured: AttributeUrlResolver::resolveRule() throws internally.
        $rule = (new AttributeReader())->rules(ObjectChangeHandlerRoutedPost::class)->get('nope');
        self::assertNotNull($rule);

        $resolved = $handler->resolve(new ObjectChangeHandlerRoutedPost(), new RuleEvent($rule, Event::Updated));

        self::assertSame([], $resolved);
        self::assertNotEmpty($logger->messages('error'));
    }

    public function testCreatedConvenienceMethodResolvesUrls(): void
    {
        $urls = array_map(static fn($r) => $r->url, self::handler()->created(new ObjectChangeHandlerPost(published: true)));

        self::assertSame(['/x'], $urls);
    }

    public function testUpdatedConvenienceMethodResolvesUrls(): void
    {
        $urls = array_map(static fn($r) => $r->url, self::handler()->updated(new ObjectChangeHandlerPost(published: true, title: 'new'), ['title']));

        self::assertSame(['/x'], $urls);
    }

    public function testDeletedConvenienceMethodResolvesUrls(): void
    {
        $urls = array_map(static fn($r) => $r->url, self::handler()->deleted(new ObjectChangeHandlerPost(published: true)));

        self::assertSame(['/x'], $urls);
    }
}
