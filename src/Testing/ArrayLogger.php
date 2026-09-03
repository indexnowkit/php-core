<?php

declare(strict_types=1);

namespace IndexNowKit\Testing;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Test double: keeps every record; messages() returns them interpolated, optionally filtered by level.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => \is_scalar($level) ? (string) $level : 'unknown', 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<string>
     */
    public function messages(?string $level = null): array
    {
        $out = [];
        foreach ($this->records as $r) {
            if ($level === null || $r['level'] === $level) {
                $out[] = self::interpolate($r['message'], $r['context']);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (\is_scalar($value) || $value === null) {
                $replace['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replace);
    }
}
