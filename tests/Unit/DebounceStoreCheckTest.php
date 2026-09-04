<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\DebounceStoreCheck;
use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DebounceStoreCheckTest extends TestCase
{
    #[TestDox('off, none, memory, a probed store and a failing probe each get their line; the adapter default applies when debounce.store is unset')]
    public function testLevels(): void
    {
        $check = new DebounceStoreCheck(Factory::config(['debounce' => ['per_url' => 0]]));
        self::assertSame([CheckLevel::Ok], self::levels($check));
        self::assertStringContainsString('debounce: off (debounce.per_url = 0)', self::messages($check)[0]);

        $check = new DebounceStoreCheck(Factory::config(['debounce' => ['per_url' => 600, 'store' => 'none']]));
        self::assertSame([CheckLevel::Ok], self::levels($check));
        self::assertStringContainsString('debounce: no store (debounce.store = none)', self::messages($check)[0]);

        $check = new DebounceStoreCheck(Factory::config(['debounce' => ['per_url' => 600]]));
        self::assertSame([CheckLevel::Warning], self::levels($check), 'the plain-PHP default is memory');
        self::assertStringContainsString('store "memory" is per-process only', self::messages($check)[0]);
        self::assertStringContainsString('Set debounce.store to a shared cache', self::messages($check)[0]);

        $probed = [];
        $check = new DebounceStoreCheck(Factory::config(['debounce' => ['per_url' => 600]]), static function (string $id) use (&$probed): string {
            $probed[] = $id;

            return \sprintf('cache store "%s" (ArrayStore)', $id);
        }, 'cache');
        self::assertSame([CheckLevel::Ok], self::levels($check));
        self::assertSame(['cache'], $probed, 'the adapter default is what gets probed');
        self::assertStringContainsString('debounce: 600s per URL, shared through cache store "cache" (ArrayStore)', self::messages($check)[0]);

        $check = new DebounceStoreCheck(Factory::config(['debounce' => ['per_url' => 600, 'store' => 'redis']]), static fn(string $id): string => throw new RuntimeException('Connection refused'));
        self::assertSame([CheckLevel::Error], self::levels($check));
        self::assertStringContainsString('debounce: store "redis" is not usable (Connection refused); URLs are still sent, the window is not applied.', self::messages($check)[0]);

        $check = new DebounceStoreCheck(Factory::config(['debounce' => ['per_url' => 600, 'store' => 'redis']]));
        self::assertSame([CheckLevel::Ok], self::levels($check), 'no probe: the store is reported, not tested');
        self::assertStringContainsString('shared through store "redis"', self::messages($check)[0]);
    }

    /**
     * @return list<CheckLevel>
     */
    private static function levels(DebounceStoreCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): CheckLevel => $item->level, $report->items());
    }

    /**
     * @return list<string>
     */
    private static function messages(DebounceStoreCheck $check): array
    {
        $report = new CheckReport();
        $check->check($report);

        return array_map(static fn($item): string => $item->message, $report->items());
    }
}
