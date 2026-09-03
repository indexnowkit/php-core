<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use Attribute;
use IndexNowKit\Attribute\Param\ParamValue;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;

/**
 * One family of public URLs of a persisted class. Repeat it once per page the object has.
 *
 * #[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: 'isPublished', fields: ['slug', 'title'])]
 * #[IndexNow(route: 'post_amp', params: ['slug' => 'slug'], when: 'hasAmp')]
 * #[IndexNow(via: 'category')]        // resubmit the category page too
 * #[IndexNow(urls: ['/'])]            // and the homepage
 *
 * Exactly one source per rule: route | resolver | via | url | urls. Class-wide policy (`when`, `fields`,
 * `events`, `locales`) goes to #[IndexNowDefaults]; a rule's `when` is ANDed with it, the other three
 * override it (null = inherit, [] = no filter).
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class IndexNow
{
    /** @var list<Event>|null */
    public array|null $events;

    /**
     * @param string|null                      $route      framework route name (resolved by the adapter's router)
     * @param array<string, string|ParamValue> $params     route parameter => accessor string ("slug", "category.slug", "self") or typed ParamValue
     * @param string|null                      $resolver   UrlResolverInterface service id or class name
     * @param string|null                      $via        accessor to a related object or collection whose pages are resubmitted (as updates)
     * @param string|null                      $url        accessor returning string|iterable<string>|null: the URL(s) themselves (Django get_absolute_url style)
     * @param list<string>                     $urls       literal URLs, absolute or base_url-relative
     * @param string|null                      $when       bool accessor: the page exists only while true; true -> false is sent as a deletion
     * @param list<string>                     $whenFields fields backing $when when its name differs from the field (for old-state detection in ORM change sets)
     * @param list<string>|null                $fields     for updates, submit only when one of these fields changed; null = inherit, [] = any
     * @param list<string|Event>|null          $events     subset of created/updated/deleted; null = inherit
     * @param list<string>|string|null         $locales    'current' | 'all' | explicit list (routes with _locale); null = inherit
     * @param string|ParamValue|null           $host       host to generate the URL on (literal, or a ParamValue read from the object) for multi-domain setups
     * @param string|null                      $name       stable rule id for logs, explain output and overriding in subclasses (default: derived from the source)
     *
     * @throws ConfigurationException with zero or several sources, an unknown event, or url/urls swapped
     */
    public function __construct(
        public ?string $route = null,
        public array $params = [],
        public ?string $resolver = null,
        public ?string $via = null,
        public ?string $url = null,
        public array $urls = [],
        public ?string $when = null,
        public array $whenFields = [],
        public ?array $fields = null,
        ?array $events = null,
        public array|string|null $locales = null,
        public string|ParamValue|null $host = null,
        public ?string $name = null,
    ) {
        $sources = array_keys(array_filter([
            'route' => $route !== null,
            'resolver' => $resolver !== null,
            'via' => $via !== null,
            'url' => $url !== null,
            'urls' => $urls !== [],
        ]));
        if (\count($sources) !== 1) {
            throw new ConfigurationException($sources === []
                ? '#[IndexNow] needs exactly one of route, resolver, via, url or urls.'
                : \sprintf('#[IndexNow] has several sources (%s); split them into separate #[IndexNow] attributes.', implode(', ', $sources)));
        }
        if ($url !== null && (str_starts_with($url, '/') || str_starts_with($url, 'http'))) {
            throw new ConfigurationException(\sprintf('#[IndexNow(url: "%s")] takes a property or method name; for a literal URL use urls: ["%s"].', $url, $url));
        }
        foreach ($urls as $literal) {
            if (!str_starts_with($literal, '/') && !str_starts_with($literal, 'http')) {
                throw new ConfigurationException(\sprintf('#[IndexNow(urls: ["%s"])] takes literal URLs (absolute or starting with /); for a property or method name use url: "%s".', $literal, $literal));
            }
        }
        $this->events = $events === null ? null : self::normalizeEvents($events, 'IndexNow');
    }

    /**
     * @param list<string|Event> $events
     *
     * @return list<Event>
     *
     * @throws ConfigurationException
     *
     * @internal shared with IndexNowDefaults and IndexNowUrl
     */
    public static function normalizeEvents(array $events, string $attribute): array
    {
        $normalized = [];
        foreach ($events as $event) {
            $normalized[] = $event instanceof Event ? $event : (Event::tryFrom($event) ?? throw new ConfigurationException(\sprintf('#[%s] unknown event "%s".', $attribute, $event)));
        }

        return $normalized;
    }
}
