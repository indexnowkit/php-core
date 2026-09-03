<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Attribute\AttributeReaderInterface;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\RuleSource;
use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;

/**
 * Default resolver: every rule of the class, each through its source (router bridge, resolver service,
 * related object, accessor, literal URLs). The `when` guard and the event subscription are applied per rule
 * here, the only place that knows which rule is being built.
 */
final class AttributeUrlResolver implements UrlResolverInterface
{
    public function __construct(
        private readonly AttributeReaderInterface $reader,
        private readonly ?RouteUrlResolverInterface $router = null,
        private readonly ?ResolverLocatorInterface $locator = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $maxViaDepth = 3,
        private readonly int $maxViaFanout = 100,
    ) {}

    /**
     * @return list<string>
     *
     * @throws ConfigurationException when a rule needs a router, locator or accessor that is not available
     */
    public function resolve(object $subject, Event $event): array
    {
        return ResolvedUrl::urls($this->explain($subject, $event));
    }

    /**
     * Same work, provenance kept.
     *
     * @return list<ResolvedUrl>
     *
     * @throws ConfigurationException
     */
    public function explain(object $subject, Event $event): array
    {
        $out = [];
        foreach ($this->reader->rules($subject) as $rule) {
            $out = [...$out, ...$this->resolveRule($subject, $rule, $event)];
        }

        return $out;
    }

    /**
     * One rule: nothing unless it listens to the event and, for Created/Updated, applies to the object's
     * current state. Deleted is resolved regardless of `when`: the caller decides that the page went away
     * (an unpublish transition leaves the object in the `when = false` state, and its URL must still be sent).
     *
     * @return list<ResolvedUrl>
     *
     * @throws ConfigurationException
     */
    public function resolveRule(object $subject, UrlRule $rule, Event $event, int $depth = 0): array
    {
        if (!$rule->listensTo($event) || ($event !== Event::Deleted && !$rule->appliesTo($subject))) {
            return [];
        }

        return match ($rule->source) {
            RuleSource::Urls => $this->wrap($rule->urls, $subject, $rule, $event),
            RuleSource::Url => $this->wrap($this->fromAccessor($subject, $rule), $subject, $rule, $event),
            RuleSource::Resolver => $this->wrap($this->fromResolver($subject, $rule, $event), $subject, $rule, $event),
            RuleSource::Via => $this->fromVia($subject, $rule, $depth),
            RuleSource::Route => $this->fromRoute($subject, $rule, $event),
        };
    }

    /**
     * @return list<ResolvedUrl>
     */
    private function fromRoute(object $subject, UrlRule $rule, Event $event): array
    {
        if ($this->router === null) {
            throw new ConfigurationException(\sprintf('%s rule "%s" uses route "%s" but no router bridge is configured. Use a framework adapter, or url:/resolver: instead of route:.', $subject::class, $rule->name, (string) $rule->route));
        }
        $host = $rule->host === null ? null : self::stringOrNull(ParamExtractor::resolve($subject, $rule->host));
        $out = [];
        foreach ($this->router->locales($rule->locales) as $locale) {
            $params = ParamExtractor::extract($subject, $rule->params, $locale, $host);
            $out[] = new ResolvedUrl($this->router->generate((string) $rule->route, $params, $locale, $host), $rule->name, $subject::class, $event, $locale);
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function fromAccessor(object $subject, UrlRule $rule): array
    {
        $value = ParamExtractor::read($subject, (string) $rule->url);
        if ($value === null) {
            return [];
        }
        if (\is_string($value)) {
            return [$value];
        }
        if (is_iterable($value)) {
            $urls = [];
            foreach ($value as $url) {
                if (!\is_string($url)) {
                    throw new ConfigurationException(\sprintf('%s::%s must yield strings, got %s.', $subject::class, (string) $rule->url, get_debug_type($url)));
                }
                $urls[] = $url;
            }

            return $urls;
        }

        throw new ConfigurationException(\sprintf('%s::%s must return string, iterable<string> or null, got %s.', $subject::class, (string) $rule->url, get_debug_type($value)));
    }

    /**
     * @return iterable<string>
     */
    private function fromResolver(object $subject, UrlRule $rule, Event $event): iterable
    {
        if ($this->locator === null) {
            throw new ConfigurationException(\sprintf('%s rule "%s" uses resolver "%s" but no resolver locator is configured.', $subject::class, $rule->name, (string) $rule->resolver));
        }

        return $this->locator->get((string) $rule->resolver)->resolve($subject, $event);
    }

    /**
     * Delegate to related objects: a changed Comment resubmits its Post's pages. Targets are always resolved
     * as Updated (their pages still exist whatever happened to the source), depth- and fan-out-limited.
     *
     * @return list<ResolvedUrl>
     */
    private function fromVia(object $subject, UrlRule $rule, int $depth): array
    {
        if ($depth >= $this->maxViaDepth) {
            throw new ConfigurationException(\sprintf('#[IndexNow(via: "%s")] on %s exceeds the maximum depth of %d; check for a cycle.', (string) $rule->via, $subject::class, $this->maxViaDepth));
        }
        $target = ParamExtractor::read($subject, (string) $rule->via);
        if ($target === null) {
            return [];
        }
        $targets = \is_object($target) && !is_iterable($target) ? [$target] : $target;
        if (!is_iterable($targets)) {
            throw new ConfigurationException(\sprintf('#[IndexNow(via: "%s")] on %s must point to an object or a collection of objects, got %s.', (string) $rule->via, $subject::class, get_debug_type($target)));
        }
        $out = [];
        $seen = 0;
        foreach ($targets as $related) {
            if (!\is_object($related)) {
                continue;
            }
            if (++$seen > $this->maxViaFanout) {
                $this->logger->warning('indexnow: #[IndexNow(via: "{via}")] on {class} stops after {max} related objects', ['via' => $rule->via, 'class' => $subject::class, 'max' => $this->maxViaFanout]);
                break;
            }
            foreach ($this->reader->rules($related) as $targetRule) {
                if ($targetRule->source === RuleSource::Via && $targetRule->via === $rule->via) {
                    continue; // A -> B -> A through the same accessor name
                }
                foreach ($this->resolveRule($related, $targetRule, Event::Updated, $depth + 1) as $resolved) {
                    $out[] = new ResolvedUrl($resolved->url, $rule->name . ' -> ' . $resolved->rule, $subject::class, Event::Updated, $resolved->locale);
                }
            }
        }

        return $out;
    }

    /**
     * @param iterable<string> $urls
     *
     * @return list<ResolvedUrl>
     */
    private function wrap(iterable $urls, object $subject, UrlRule $rule, Event $event): array
    {
        $out = [];
        foreach ($urls as $url) {
            $out[] = new ResolvedUrl($url, $rule->name, $subject::class, $event);
        }

        return $out;
    }

    /**
     * @throws ConfigurationException
     */
    private static function stringOrNull(mixed $value): ?string
    {
        if ($value === null || \is_string($value)) {
            return $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new ConfigurationException(\sprintf('#[IndexNow(host: ...)] must resolve to a string, got %s.', get_debug_type($value)));
    }
}
