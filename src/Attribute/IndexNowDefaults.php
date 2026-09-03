<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use Attribute;
use Closure;
use IndexNowKit\Attribute\Param\ParamValue;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;

/**
 * Class-level policy shared by every #[IndexNow] rule of the class and its subclasses.
 *
 * `when` is ANDed with each rule's own `when` (a page of a draft is never public, whatever the rule says);
 * `fields`, `events` and `locales` are defaults a rule may override.
 *
 * #[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body'])]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class IndexNowDefaults
{
    /** @var list<Event>|null */
    public array|null $events;

    /**
     * @param list<string>             $whenFields
     * @param list<string>|null        $fields
     * @param list<string|Event>|null  $events
     * @param list<string>|string|null $locales
     *
     * @throws ConfigurationException on an unknown event name
     */
    public function __construct(
        public string|ParamValue|Closure|null $when = null,
        public array $whenFields = [],
        public ?array $fields = null,
        ?array $events = null,
        public array|string|null $locales = null,
    ) {
        $this->events = $events === null ? null : IndexNow::normalizeEvents($events, 'IndexNowDefaults');
    }
}
