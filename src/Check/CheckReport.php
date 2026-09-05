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

    /**
     * An "all good" line. Writers are public: every CheckInterface implementation calls them.
     *
     * @param string|null $code stable identifier of the check ({@see CheckItem::$code}); name one in every shipped check
     * @param string|null $host the host the line is about, when it is about one
     */
    public function ok(string $message, ?string $code = null, ?string $host = null): void
    {
        $this->items[] = new CheckItem(CheckLevel::Ok, $message, $code, $host);
    }

    /** Something to look at that does not stop submissions (`check --strict` exits 1 on it). */
    public function warning(string $message, ?string $code = null, ?string $host = null): void
    {
        $this->items[] = new CheckItem(CheckLevel::Warning, $message, $code, $host);
    }

    /** A problem that stops submissions or the key file; makes the check command exit 1. */
    public function error(string $message, ?string $code = null, ?string $host = null): void
    {
        $this->items[] = new CheckItem(CheckLevel::Error, $message, $code, $host);
    }

    /** A ready-made line (merging reports, re-emitting a line of another report). */
    public function add(CheckItem $item): void
    {
        $this->items[] = $item;
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
        return $this->has(CheckLevel::Error);
    }

    public function hasWarnings(): bool
    {
        return $this->has(CheckLevel::Warning);
    }

    /** The worst level in the report: `error` when any line is an error, else `warning`, else `ok` (the `status` of `check --json`). */
    public function status(): CheckLevel
    {
        return match (true) {
            $this->hasErrors() => CheckLevel::Error,
            $this->hasWarnings() => CheckLevel::Warning,
            default => CheckLevel::Ok,
        };
    }

    private function has(CheckLevel $level): bool
    {
        foreach ($this->items as $item) {
            if ($item->level === $level) {
                return true;
            }
        }

        return false;
    }
}
