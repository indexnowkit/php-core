<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Event;

/**
 * A URL plus the rule of the class that produced it. For explain output, logs and profilers; the wire and
 * UrlResolverInterface see plain strings.
 */
final readonly class ResolvedUrl
{
    /**
     * @param string       $rule  rule name; "via:category -> category_show" for delegated rules
     * @param class-string $class
     */
    public function __construct(
        public string $url,
        public string $rule,
        public string $class,
        public Event $event,
        public ?string $locale = null,
    ) {}

    /**
     * "App\Entity\Post#post_amp"
     */
    public function source(): string
    {
        return $this->class . '#' . $this->rule;
    }

    /**
     * @param iterable<ResolvedUrl> $resolved
     *
     * @return list<string> de-duplicated, first occurrence order
     */
    public static function urls(iterable $resolved): array
    {
        $urls = [];
        foreach ($resolved as $item) {
            $urls[$item->url] = true;
        }

        return array_keys($urls);
    }
}
