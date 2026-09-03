<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

use ArrayIterator;
use Countable;
use IndexNowKit\Event;
use IteratorAggregate;
use Traversable;

/**
 * Every URL rule of one class, in declaration order (parents first). Empty for classes without rules, so
 * callers never branch on null.
 *
 * @implements IteratorAggregate<int, UrlRule>
 */
final readonly class RuleSet implements IteratorAggregate, Countable
{
    /**
     * @param class-string  $class
     * @param list<UrlRule> $rules
     */
    public function __construct(public string $class, public array $rules = []) {}

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    public function count(): int
    {
        return \count($this->rules);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->rules);
    }

    public function get(string $name): ?UrlRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->name === $name) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Cheap pre-filter for ORM hooks: does any rule care about this event?
     */
    public function listensTo(Event $event): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->listensTo($event)) {
                return true;
            }
        }

        return false;
    }
}
