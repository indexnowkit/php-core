<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use Attribute;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;

/**
 * On a public method returning string|iterable<string>|null: its result is a URL family of the object.
 * Same as #[IndexNow(url: '<method>')] on the class (Django get_absolute_url() convention).
 *
 * #[IndexNowUrl(when: 'isLive')]
 * public function getPublicUrl(): string { return '/offers/' . $this->code; }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class IndexNowUrl
{
    /** @var list<Event>|null */
    public array|null $events;

    /**
     * @param list<string>            $whenFields
     * @param list<string>|null       $fields
     * @param list<string|Event>|null $events
     *
     * @throws ConfigurationException on an unknown event name
     */
    public function __construct(
        public ?string $when = null,
        public array $whenFields = [],
        public ?array $fields = null,
        ?array $events = null,
        public ?string $name = null,
    ) {
        $this->events = $events === null ? null : IndexNow::normalizeEvents($events, 'IndexNowUrl');
    }
}
