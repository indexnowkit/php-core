<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Retry\ForbiddenCounter;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Tests\Support\GeneratorCache;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ForbiddenCounterTest extends TestCase
{
    private const HOST = 'www.example.com';

    #[TestDox('in the process: hit() counts, escalates exactly when the threshold is reached, reset() forgets the host')]
    public function testInProcess(): void
    {
        $counter = new ForbiddenCounter(null, 'app_', 3);

        self::assertSame(0, $counter->count(self::HOST));
        self::assertFalse($counter->isEscalated(self::HOST));
        self::assertSame([1, false], $counter->hit(self::HOST));
        self::assertSame([2, false], $counter->hit(self::HOST));
        self::assertSame([3, true], $counter->hit(self::HOST), 'the third hit crosses the threshold');
        self::assertSame([4, false], $counter->hit(self::HOST), 'once per streak');
        self::assertSame(4, $counter->count(self::HOST));
        self::assertTrue($counter->isEscalated(self::HOST));
        self::assertSame(0, $counter->count('other.example.com'), 'per host');

        $counter->reset(self::HOST);
        self::assertSame(0, $counter->count(self::HOST));
        self::assertFalse($counter->isEscalated(self::HOST));
        self::assertSame([1, false], $counter->hit(self::HOST), 'a new streak');
        self::assertSame(3, $counter->threshold());
    }

    #[TestDox('in a PSR-16 cache: two counters share the streak and the escalation flag under <prefix>403.<host>, with the TTL; reset() deletes both keys')]
    public function testShared(): void
    {
        $cache = new GeneratorCache();
        $a = new ForbiddenCounter($cache, 'app_', 3, 120);
        $b = new ForbiddenCounter($cache, 'app_', 3, 120);

        self::assertSame('app_403.www.example.com', $a->key(self::HOST));
        self::assertSame('app_403.www.example.com_escalated', $a->key(self::HOST, true));
        self::assertSame([1, false], $a->hit(self::HOST));
        self::assertSame([2, false], $b->hit(self::HOST));
        self::assertSame(2, $a->count(self::HOST));
        self::assertSame([3, true], $a->hit(self::HOST));
        self::assertTrue($b->isEscalated(self::HOST), 'the flag is shared');
        self::assertSame([4, false], $b->hit(self::HOST), 'the other counter does not escalate again');
        self::assertSame(4, $cache->get('app_403.www.example.com'));
        self::assertTrue((bool) $cache->get('app_403.www.example.com_escalated'));

        $b->reset(self::HOST);
        self::assertFalse($cache->has('app_403.www.example.com'));
        self::assertFalse($cache->has('app_403.www.example.com_escalated'));
        self::assertSame(0, $a->count(self::HOST));
        self::assertFalse($a->isEscalated(self::HOST));
        $b->reset(self::HOST); // nothing stored: no delete
    }

    #[TestDox('a store with increment() counts atomically and the fresh counter gets the TTL')]
    public function testIncrement(): void
    {
        $atomic = new class extends GeneratorCache {
            /** @var list<array{0: string, 1: mixed, 2: mixed}> */
            public array $sets = [];
            /** @var array<string, int> */
            public array $counters = [];

            public function increment(string $key, int $by = 1): int
            {
                return $this->counters[$key] = ($this->counters[$key] ?? 0) + $by;
            }

            public function set($key, $value, $ttl = null): bool
            {
                $this->sets[] = [$key, $value, $ttl];

                return parent::set($key, $value, $ttl);
            }
        };
        $counter = new ForbiddenCounter($atomic, 'app_', 2, 45);
        self::assertSame([1, false], $counter->hit(self::HOST));
        self::assertSame([2, true], $counter->hit(self::HOST));
        self::assertSame(2, $atomic->counters['app_403.www.example.com']);
        self::assertSame([['app_403.www.example.com', 1, 45], ['app_403.www.example.com_escalated', true, 45]], $atomic->sets);
    }

    #[TestDox('a failing cache is logged once as a warning; the counter falls back to the process, count() and isEscalated() answer 0 / false')]
    public function testBrokenCache(): void
    {
        $broken = new class extends GeneratorCache {
            public function get($key, $default = null): mixed
            {
                throw new RuntimeException('redis down');
            }
        };
        $logger = new ArrayLogger();
        $counter = new ForbiddenCounter($broken, 'app_', 2, 45, $logger);

        self::assertSame([1, false], $counter->hit(self::HOST));
        self::assertSame([2, true], $counter->hit(self::HOST));
        self::assertSame(0, $counter->count(self::HOST), 'the shared value is unknown');
        self::assertFalse($counter->isEscalated(self::HOST));
        $counter->reset(self::HOST);
        self::assertCount(1, $logger->messages('warning'), 'one warning for the process');
        self::assertStringContainsString('failure cache unavailable', $logger->messages('warning')[0]);
    }
}
