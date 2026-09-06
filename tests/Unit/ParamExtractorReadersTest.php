<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use ArrayIterator;
use IndexNowKit\Attribute\Param\Equals;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\SubjectReaderInterface;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Stringable;

/** An Active-Record-like object: fields in an array behind __get(), invisible to the DSL. */
final class ReadersRecord implements Stringable
{
    /** @param array<string, mixed> $fields */
    public function __construct(public array $fields) {}

    public function __get(string $name): mixed
    {
        return $this->fields[$name] ?? null;
    }

    public function isPublished(): bool
    {
        return false; // the reader claims `published` first; `isPublished` is not a field and goes to the DSL
    }

    public function __toString(): string
    {
        return json_encode($this->fields, JSON_THROW_ON_ERROR);
    }
}

/** A plain object holding a record: the DSL reads the property, and the record is then just a Stringable. */
final class ReadersRecordHolder
{
    public function __construct(public readonly ReadersRecord $child) {}
}

final class ReadersFieldReader implements SubjectReaderInterface
{
    public function supports(object $subject): bool
    {
        return $subject instanceof ReadersRecord;
    }

    public function has(object $subject, string $accessor): bool
    {
        return $subject instanceof ReadersRecord && \array_key_exists($accessor, $subject->fields);
    }

    public function read(object $subject, string $accessor): mixed
    {
        \assert($subject instanceof ReadersRecord);

        return $subject->fields[$accessor];
    }
}

final class ParamExtractorReadersTest extends TestCase
{
    #[TestDox('a reader given to the constructor claims single-segment accessors before the DSL')]
    public function testReaderBeforeDsl(): void
    {
        $extractor = new ParamExtractor(new ReadersFieldReader());
        $record = new ReadersRecord(['slug' => 'hello', 'published' => true, 'parent' => new ReadersRecord(['slug' => 'parent'])]);

        self::assertSame('hello', $extractor->read($record, 'slug'));
        self::assertTrue($extractor->read($record, 'published'), 'the field wins over isPublished()');
        self::assertFalse($extractor->read($record, 'isPublished'), 'not a field: the DSL method');
        self::assertSame('parent', $extractor->read($record, 'parent.slug'), 'dotted paths go through the reader per segment');
    }

    #[TestDox('an object a reader supports stays an object in params (route model binding), a Stringable nobody supports becomes a string')]
    public function testCoercion(): void
    {
        $extractor = new ParamExtractor(new ReadersFieldReader());
        $child = new ReadersRecord(['slug' => 'child']);
        $record = new ReadersRecord(['child' => $child]);

        self::assertSame(['c' => $child], $extractor->extract($record, ['c' => 'child']), 'supported by a reader: stays an object for route model binding');
        self::assertSame(['c' => (string) $child], (new ParamExtractor())->extract($child->fields === [] ? $record : new ReadersRecordHolder($child), ['c' => 'child']), 'no reader supports it: Stringable becomes a string');
    }

    #[TestDox('a FieldCondition is evaluated through the readers: Equals sees the record field')]
    public function testFieldConditionThroughReaders(): void
    {
        $extractor = new ParamExtractor(new ReadersFieldReader());
        $record = new ReadersRecord(['status' => 'published']);

        self::assertTrue($extractor->condition($record, new Equals('status', 'published')));
        self::assertFalse($extractor->condition($record, new Equals('status', 'draft')));
        self::assertTrue($extractor->condition($record, 'status'), 'a string accessor is truthy through the reader too');
    }

    #[TestDox('without a reader the record is opaque, and the error names the readers that were asked')]
    public function testErrorNamesReaders(): void
    {
        try {
            (new ParamExtractor())->read(new ReadersRecord(['slug' => 'x']), 'slug');
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('give the ParamExtractor a SubjectReaderInterface', $e->getMessage());
            self::assertStringNotContainsString('none of the readers', $e->getMessage());
        }
        try {
            (new ParamExtractor(new ReadersFieldReader()))->read(new ReadersRecord(['slug' => 'x']), 'missing');
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('none of the readers (' . ReadersFieldReader::class . ') claims it', $e->getMessage());
        }
    }

    #[TestDox('with() appends readers into a new instance; fromReaders() takes an iterable')]
    public function testComposition(): void
    {
        $base = new ParamExtractor();
        $extended = $base->with(new ReadersFieldReader());

        self::assertSame([], $base->readers(), 'the original is untouched');
        self::assertCount(1, $extended->readers());
        self::assertSame('x', $extended->read(new ReadersRecord(['slug' => 'x']), 'slug'));
        self::assertCount(1, ParamExtractor::fromReaders(new ArrayIterator([new ReadersFieldReader()]))->readers());
    }
}
