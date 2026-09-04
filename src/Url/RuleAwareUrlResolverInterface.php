<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Attribute\UrlRule;
use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;

/**
 * A resolver that can resolve one rule at a time and say which rule produced each URL. {@see GuardedUrlResolver}
 * uses it for per-rule guarding (a failing rule loses its own URLs only) and `explain`; {@see AttributeUrlResolver}
 * is the shipped implementation. A custom top-level resolver that implements only {@see UrlResolverInterface} is
 * resolved as a whole object instead.
 *
 * "Implement" tier (docs/bc.md), with one caveat until 1.0: a method may still be appended in a minor.
 */
interface RuleAwareUrlResolverInterface extends UrlResolverInterface
{
    /**
     * One rule: nothing unless it listens to the event and applies to the object's current state.
     *
     * @param int  $depth      `via:` hops already taken (the resolver stops at `resolver.max_via_depth`)
     * @param bool $ignoreWhen resolve even if `when` is false now (an already classified unpublish transition)
     *
     * @return list<ResolvedUrl>
     *
     * @throws ConfigurationException when the rule needs a router, locator or accessor that is not available
     */
    public function resolveRule(object $subject, UrlRule $rule, Event $event, int $depth = 0, bool $ignoreWhen = false): array;

    /**
     * Every rule of the object, provenance kept.
     *
     * @return list<ResolvedUrl>
     *
     * @throws ConfigurationException
     */
    public function explain(object $subject, Event $event): array;
}
