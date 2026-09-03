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

final class ObjectChangeHandlerTest extends TestCase
{
    private static function handler(?ArrayLogger $logger = null): ObjectChangeHandler
    {
        $logger ??= new ArrayLogger();
        $reader = new AttributeReader();
        $guarded = new GuardedUrlResolver(new AttributeUrlResolver($reader), $reader, $logger);

        return new ObjectChangeHandler($reader, $guarded, $logger);
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
