<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\PublishGuard;
use IndexNowKit\Event;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The single place that turns "an object changed" into URLs: reads #[IndexNow], checks the event
 * subscription and the `when` guard (skipped for deletions), delegates to the real resolver, and
 * turns any exception into an error log plus an empty list. ORM hooks must go through it so a typo
 * in an attribute never breaks the host application's flush.
 */
final class GuardedUrlResolver implements UrlResolverInterface
{
    public function __construct(
        private readonly UrlResolverInterface $inner,
        private readonly AttributeReaderInterface $attributes,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return list<string>
     */
    public function resolve(object $subject, Event $event): array
    {
        try {
            $attribute = $this->attributes->read($subject);
            if ($attribute === null || !$attribute->listensTo($event)) {
                return [];
            }
            if ($event !== Event::Deleted && !PublishGuard::isPublished($subject, $attribute)) {
                return [];
            }
            $urls = [];
            foreach ($this->inner->resolve($subject, $event) as $url) {
                $urls[] = $url;
            }

            return $urls;
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot resolve URLs for {class} ({event}): {error}', ['class' => $subject::class, 'event' => $event->value, 'error' => $e->getMessage(), 'exception' => $e]);

            return [];
        }
    }
}
