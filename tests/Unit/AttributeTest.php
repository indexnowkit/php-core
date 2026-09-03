<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\IndexNow;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\Event;
use IndexNowKit\Url\ParamExtractor;
use IndexNowKit\Url\PublishGuard;
use PHPUnit\Framework\TestCase;

#[IndexNow(route: 'post_show', params: ['slug' => 'slug', 'author' => 'author.name'], when: 'isPublished', events: ['created', 'updated'], fields: ['slug', 'title'])]
class AttributePost
{
    public function __construct(public string $slug = 'hello', public bool $published = true, public AttributeAuthor $author = new AttributeAuthor()) {}

    public function isPublished(): bool
    {
        return $this->published;
    }
}

class AttributeAuthor
{
    public function getName(): string
    {
        return 'ann';
    }
}

final class AttributeChildPost extends AttributePost {}

final class NotAnnotated {}

final class AttributeTest extends TestCase
{
    public function testReadAndInherit(): void
    {
        $reader = new AttributeReader();
        $attr = $reader->read(AttributeChildPost::class);
        self::assertNotNull($attr);
        self::assertSame('post_show', $attr->route);
        self::assertSame([Event::Created, Event::Updated], $attr->events);
        self::assertTrue($attr->listensTo(Event::Created));
        self::assertFalse($attr->listensTo(Event::Deleted));
        self::assertNull($reader->read(new NotAnnotated()));
        self::assertSame($attr, $reader->read(AttributeChildPost::class), 'cached');
    }

    public function testFieldsFilter(): void
    {
        $attr = (new AttributeReader())->read(AttributePost::class);
        self::assertNotNull($attr);
        self::assertTrue($attr->caresAbout(['title', 'views']));
        self::assertFalse($attr->caresAbout(['views']));
        self::assertTrue((new IndexNow(route: 'x'))->caresAbout(['anything']));
    }

    public function testParamExtractorAndGuard(): void
    {
        $post = new AttributePost(slug: 's1', published: false);
        $attr = (new AttributeReader())->read($post);
        self::assertNotNull($attr);
        self::assertSame(['slug' => 's1', 'author' => 'ann'], ParamExtractor::extract($post, $attr->params));
        self::assertSame($post, ParamExtractor::read($post, 'self'));
        self::assertFalse(PublishGuard::isPublished($post, $attr));
        $post->published = true;
        self::assertTrue(PublishGuard::isPublished($post, $attr));
    }

    public function testRequiresRouteOrResolver(): void
    {
        $this->expectException(ConfigurationException::class);
        new IndexNow();
    }

    public function testUnknownAccessor(): void
    {
        $this->expectException(ConfigurationException::class);
        ParamExtractor::read(new NotAnnotated(), 'nope');
    }
}
