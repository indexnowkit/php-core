<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Event;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The never-throwing "an object changed -> URLs" entry point. Wraps any resolver: an exception (invalid
 * attribute, missing accessor, router failure) becomes an error log entry plus an empty list, so a typo in
 * an attribute never breaks the host application's flush. Silent outcomes are logged at debug level.
 *
 * With the default AttributeUrlResolver the guards (`when`, `events`) are applied per rule inside it; a
 * hand-written top-level resolver keeps the class-level event subscription check here.
 */
final class GuardedUrlResolver implements UrlResolverInterface
{
    public function __construct(
        private readonly UrlResolverInterface $inner,
        private readonly AttributeReaderInterface $attributes,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return list<string> de-duplicated
     */
    public function resolve(object $subject, Event $event): array
    {
        return ResolvedUrl::urls($this->explain($subject, $event));
    }

    /**
     * @return list<ResolvedUrl>
     */
    public function explain(object $subject, Event $event): array
    {
        try {
            if ($this->inner instanceof AttributeUrlResolver) {
                $resolved = $this->inner->explain($subject, $event);
            } else {
                $rules = $this->attributes->rules($subject);
                if (!$rules->isEmpty() && !$rules->listensTo($event)) {
                    $this->logger->debug('indexnow: {class} does not subscribe to {event}', ['class' => $subject::class, 'event' => $event->value]);

                    return [];
                }
                $resolved = [];
                foreach ($this->inner->resolve($subject, $event) as $url) {
                    $resolved[] = new ResolvedUrl($url, 'custom', $subject::class, $event);
                }
            }
            if ($resolved === []) {
                $this->logger->debug('indexnow: no URLs for {class} ({event}): no rule applies (no #[IndexNow], event not subscribed, or `when` is false)', ['class' => $subject::class, 'event' => $event->value]);
            }

            return $resolved;
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot resolve URLs for {class} ({event}): {error}', ['class' => $subject::class, 'event' => $event->value, 'error' => $e->getMessage(), 'exception' => $e]);

            return [];
        }
    }

    /**
     * Whether resolveRule() can resolve a single rule; a custom top-level resolver resolves whole objects only,
     * so callers should de-duplicate (object, event) pairs before calling resolveRule() with it.
     */
    public function isRuleAware(): bool
    {
        return $this->inner instanceof AttributeUrlResolver;
    }

    /**
     * One rule, never throws. With a non-attribute inner resolver the whole object is resolved instead.
     *
     * @return list<ResolvedUrl>
     */
    public function resolveRule(object $subject, UrlRule $rule, Event $event): array
    {
        if (!$this->inner instanceof AttributeUrlResolver) {
            return $this->explain($subject, $event);
        }
        try {
            return $this->inner->resolveRule($subject, $rule, $event);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: cannot resolve URLs for {class} rule "{rule}" ({event}): {error}', ['class' => $subject::class, 'rule' => $rule->name, 'event' => $event->value, 'error' => $e->getMessage(), 'exception' => $e]);

            return [];
        }
    }
}
