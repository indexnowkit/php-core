<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use Attribute;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;

/**
 * Marks a persisted class whose public page should be pushed to search engines when it changes.
 *
 * #[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: 'isPublished', fields: ['slug', 'title'])]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class IndexNow
{
    /** @var list<Event> */
    public array $events;

    /**
     * @param string|null                  $route    framework route name (resolved by the adapter's router)
     * @param array<string, string>        $params   route parameter => property/getter/"self"/dotted path
     * @param class-string|null            $resolver UrlResolverInterface implementation (service id or FQCN)
     * @param string|null                  $when     bool property/method: submit only while true; true->false is sent as a deletion
     * @param list<string|Event>           $events   subset of created/updated/deleted
     * @param list<string>                 $fields   for updates, submit only when one of these fields changed (empty = any)
     * @param list<string>|string          $locales  'current' | 'all' | explicit list (adapters with localized routes)
     *
     * @throws ConfigurationException without route/resolver or with an unknown event name
     */
    public function __construct(
        public ?string $route = null,
        public array $params = [],
        public ?string $resolver = null,
        public ?string $when = null,
        array $events = [Event::Created, Event::Updated, Event::Deleted],
        public array $fields = [],
        public array|string $locales = 'current',
    ) {
        if ($route === null && $resolver === null) {
            throw new ConfigurationException('#[IndexNow] needs either "route" or "resolver".');
        }
        $normalized = [];
        foreach ($events as $event) {
            $normalized[] = $event instanceof Event ? $event : (Event::tryFrom($event) ?? throw new ConfigurationException(\sprintf('#[IndexNow] unknown event "%s".', $event)));
        }
        $this->events = $normalized;
    }

    public function listensTo(Event $event): bool
    {
        return \in_array($event, $this->events, true);
    }

    /**
     * @param list<string> $changedFields
     */
    public function caresAbout(array $changedFields): bool
    {
        return $this->fields === [] || array_intersect($this->fields, $changedFields) !== [];
    }
}
