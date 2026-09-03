<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

/**
 * Ordered list of ok / warning / error lines produced by Checker.
 */
final class CheckReport
{
    /** @var list<array{level: 'ok'|'warning'|'error', message: string}> */
    private array $items = [];

    public function ok(string $message): void
    {
        $this->items[] = ['level' => 'ok', 'message' => $message];
    }

    public function warning(string $message): void
    {
        $this->items[] = ['level' => 'warning', 'message' => $message];
    }

    public function error(string $message): void
    {
        $this->items[] = ['level' => 'error', 'message' => $message];
    }

    /**
     * @return list<array{level: 'ok'|'warning'|'error', message: string}>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function hasErrors(): bool
    {
        foreach ($this->items as $item) {
            if ($item['level'] === 'error') {
                return true;
            }
        }

        return false;
    }
}
