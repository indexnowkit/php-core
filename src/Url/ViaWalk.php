<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use SplObjectStorage;

/**
 * One `via` walk of {@see AttributeUrlResolver}: the objects already visited (a cycle through different accessor names,
 * `Post::$tags -> Tag::$posts`, or two paths to the same object) and the total number of related objects the walk may
 * still resolve, so depth × fan-out is a hard ceiling and not just two independent ones.
 *
 * @internal
 */
final class ViaWalk
{
    /** @var SplObjectStorage<object, null> */
    private SplObjectStorage $visited;
    private int $left;

    public function __construct(public readonly int $budget)
    {
        $this->visited = new SplObjectStorage();
        $this->left = $budget;
    }

    public function visit(object $subject): void
    {
        $this->visited->attach($subject);
    }

    public function visited(object $subject): bool
    {
        return $this->visited->contains($subject);
    }

    /** One more related object to resolve; false when the budget is spent. */
    public function spend(): bool
    {
        if ($this->left <= 0) {
            return false;
        }
        --$this->left;

        return true;
    }
}
