<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use IndexNowKit\Event;

/**
 * A rule that must be resolved for an object because of a lifecycle event (the unit of work of ORM hooks).
 */
final readonly class RuleEvent
{
    public function __construct(public UrlRule $rule, public Event $event) {}
}
