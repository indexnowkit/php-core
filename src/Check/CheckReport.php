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

    /** @internal writers are for Checker and adapter-side checks */
    public function ok(string $message): void
    {
        $this->items[] = new CheckItem(CheckLevel::Ok, $message);
    }

    /** @internal */
    public function warning(string $message): void
    {
        $this->items[] = new CheckItem(CheckLevel::Warning, $message);
    }

    /** @internal */
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
