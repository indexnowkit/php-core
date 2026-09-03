<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\ChangeClassifier;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChangeClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{IndexNow, list<string>, array{mixed, mixed}|null, Event|null}>
     */
    public static function cases(): iterable
    {
        $all = new IndexNow(route: 'r', when: 'published', fields: ['title']);
        yield 'published -> draft is a deletion' => [$all, ['published'], [true, false], Event::Deleted];
        yield 'draft -> published is a creation' => [$all, ['published'], [false, true], Event::Created];
        yield 'watched field changed' => [$all, ['title'], null, Event::Updated];
        yield 'unwatched field changed' => [$all, ['views'], null, null];
        yield 'when unchanged, watched field changed' => [$all, ['title', 'published'], [true, true], Event::Updated];
        yield 'no fields filter: any change is an update' => [new IndexNow(route: 'r'), ['views'], null, Event::Updated];
        yield 'deletion not subscribed' => [new IndexNow(route: 'r', when: 'published', events: ['created', 'updated']), ['published'], [true, false], null];
        yield 'creation not subscribed' => [new IndexNow(route: 'r', when: 'published', events: ['deleted']), ['published'], [false, true], null];
        yield 'updates not subscribed' => [new IndexNow(route: 'r', events: ['created']), ['title'], null, null];
        yield 'truthy strings count as published' => [$all, ['published'], ['1', ''], Event::Deleted];
    }

    /**
     * @param list<string>             $changedFields
     * @param array{mixed, mixed}|null $whenChange
     */
    #[DataProvider('cases')]
    public function testClassify(IndexNow $attribute, array $changedFields, ?array $whenChange, ?Event $expected): void
    {
        self::assertSame($expected, ChangeClassifier::classify($attribute, $changedFields, $whenChange));
    }
}
