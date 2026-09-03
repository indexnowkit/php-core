<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\ChangeClassifier;
use IndexNowKit\Attribute\RuleSource;
use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Event;
use PHPUnit\Framework\TestCase;

final class ChangeClassifierPost
{
    public function __construct(public bool $published = true, public string $title = 'x', public int $views = 0) {}

    public function isPublished(): bool
    {
        return $this->published;
    }
}

final class ChangeClassifierFeaturedPost
{
    public function __construct(public bool $published = true, public bool $featured = true) {}

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }
}

final class ChangeClassifierLiveEntity
{
    public function __construct(public string $status = 'draft') {}

    public function isLive(): bool
    {
        return $this->status === 'published';
    }
}

final class ChangeClassifierTest extends TestCase
{
    /**
     * @param list<string> $when
     * @param list<string> $whenFields
     * @param list<string> $fields
     * @param list<Event>  $events
     */
    private static function rule(array $when = [], array $whenFields = [], array $fields = [], array $events = [Event::Created, Event::Updated, Event::Deleted]): UrlRule
    {
        return new UrlRule(
            name: 'r',
            source: RuleSource::Route,
            route: 'r',
            when: $when,
            whenFields: $whenFields,
            fields: $fields,
            events: $events,
        );
    }

    public function testChangeSetTransitionTrueToFalseIsADeletion(): void
    {
        $rule = self::rule(when: ['isPublished'], fields: ['title']);
        $subject = new ChangeClassifierPost(published: false);

        $event = ChangeClassifier::classify($rule, $subject, ['published'], ['published' => [true, false]]);

        self::assertSame(Event::Deleted, $event);
    }

    public function testChangeSetTransitionFalseToTrueIsACreation(): void
    {
        $rule = self::rule(when: ['isPublished'], fields: ['title']);
        $subject = new ChangeClassifierPost(published: true);

        $event = ChangeClassifier::classify($rule, $subject, ['published'], ['published' => [false, true]]);

        self::assertSame(Event::Created, $event);
    }

    public function testWhenAsAPlainPropertyNameWorksTheSameAsAGetter(): void
    {
        $rule = self::rule(when: ['published'], fields: ['title']);
        $subject = new ChangeClassifierPost(published: false);

        $event = ChangeClassifier::classify($rule, $subject, ['published'], ['published' => [true, false]]);

        self::assertSame(Event::Deleted, $event);
    }

    public function testWatchedFieldChangeIsAnUpdate(): void
    {
        $rule = self::rule(fields: ['title']);
        $subject = new ChangeClassifierPost();

        self::assertSame(Event::Updated, ChangeClassifier::classify($rule, $subject, ['title']));
    }

    public function testUnwatchedFieldChangeYieldsNull(): void
    {
        $rule = self::rule(fields: ['title']);
        $subject = new ChangeClassifierPost();

        self::assertNull(ChangeClassifier::classify($rule, $subject, ['views']));
    }

    public function testNoFieldsFilterMeansAnyChangeIsAnUpdate(): void
    {
        $rule = self::rule();
        $subject = new ChangeClassifierPost();

        self::assertSame(Event::Updated, ChangeClassifier::classify($rule, $subject, ['views']));
    }

    public function testWhenFieldsWithADifferentlyNamedGetterAfterFalseIsADeletion(): void
    {
        $rule = self::rule(when: ['isLive'], whenFields: ['status']);
        $subject = new ChangeClassifierLiveEntity(status: 'draft');

        $event = ChangeClassifier::classify($rule, $subject, ['status']);

        self::assertSame(Event::Deleted, $event, 'unknown old state guesses the opposite of the current (correct) state');
    }

    public function testWhenFieldsWithADifferentlyNamedGetterAfterTrueIsACreation(): void
    {
        $rule = self::rule(when: ['isLive'], whenFields: ['status']);
        $subject = new ChangeClassifierLiveEntity(status: 'published');

        $event = ChangeClassifier::classify($rule, $subject, ['status']);

        self::assertSame(Event::Created, $event);
    }

    public function testUnrelatedFieldChangeWithFieldsFilterMissYieldsNull(): void
    {
        $rule = self::rule(fields: ['title']);
        $subject = new ChangeClassifierPost();

        self::assertNull(ChangeClassifier::classify($rule, $subject, ['views']));
    }

    public function testFieldsFilterHitYieldsUpdate(): void
    {
        $rule = self::rule(fields: ['title']);
        $subject = new ChangeClassifierPost();

        self::assertSame(Event::Updated, ChangeClassifier::classify($rule, $subject, ['title']));
    }

    public function testTwoWhenAccessorsBothMustBeTrueForTheRuleToApply(): void
    {
        $rule = self::rule(when: ['isPublished', 'isFeatured']);

        $bothTrue = new ChangeClassifierFeaturedPost(published: true, featured: true);
        self::assertSame(Event::Updated, ChangeClassifier::classify($rule, $bothTrue, ['title']));

        $featuredOnly = new ChangeClassifierFeaturedPost(published: false, featured: true);
        self::assertNull(ChangeClassifier::classify($rule, $featuredOnly, ['title']), 'not applicable: `when` is false and there is no transition');
    }

    public function testTwoWhenAccessorsTransitionToBothTrueIsACreation(): void
    {
        $rule = self::rule(when: ['isPublished', 'isFeatured']);
        $subject = new ChangeClassifierFeaturedPost(published: true, featured: true);

        $event = ChangeClassifier::classify($rule, $subject, ['published'], ['published' => [false, true]]);

        self::assertSame(Event::Created, $event);
    }

    public function testCreationNotSubscribedYieldsNullEvenOnATransition(): void
    {
        $rule = self::rule(when: ['isPublished'], events: [Event::Updated]);
        $subject = new ChangeClassifierPost(published: true);

        $event = ChangeClassifier::classify($rule, $subject, ['published'], ['published' => [false, true]]);

        self::assertNull($event, 'Created is not in the subscribed events');
    }

    public function testDeletionNotSubscribedYieldsNullEvenOnATransition(): void
    {
        $rule = self::rule(when: ['isPublished'], events: [Event::Updated]);
        $subject = new ChangeClassifierPost(published: false);

        $event = ChangeClassifier::classify($rule, $subject, ['published'], ['published' => [true, false]]);

        self::assertNull($event, 'Deleted is not in the subscribed events');
    }

    public function testUpdateNotSubscribedYieldsNull(): void
    {
        $rule = self::rule(events: [Event::Created]);
        $subject = new ChangeClassifierPost();

        self::assertNull(ChangeClassifier::classify($rule, $subject, ['title']));
    }
}
