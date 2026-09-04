<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

/**
 * Ordered list of ok / warning / error lines produced by Checker.
 */
final class CheckReport
{
    /** @var list<CheckItem> */
    private array $items = [];

    /** An "all good" line. Writers are public: every CheckInterface implementation calls them. */
    public function ok(string $message): void
    {
        $this->items[] = new CheckItem(CheckLevel::Ok, $message);
    }

    /** Something to look at that does not stop submissions. */
    public function warning(string $message): void
    {
        $this->items[] = new CheckItem(CheckLevel::Warning, $message);
    }

    /** A problem that stops submissions or the key file; makes the check command exit 1. */
    public function error(string $message): void
    {
        $this->items[] = new CheckItem(CheckLevel::Error, $message);
    }

    /**
     * @return list<CheckItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function hasErrors(): bool
    {
        foreach ($this->items as $item) {
            if ($item->level === CheckLevel::Error) {
                return true;
            }
        }

        return false;
    }
}
