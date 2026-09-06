<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use DateTimeImmutable;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\ParamExtractor;
use IndexNowKit\Attribute\SubjectReaderInterface;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Hook\ObserverHelper;
use IndexNowKit\Http\Response;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Retry\ForbiddenCounter;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Testing\FrozenClock;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Tests\Support\GeneratorCache;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Transaction\TransactionStaging;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\ObjectChangeHandler;
use LogicException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use stdClass;

/** A clock that moves when the bucket sleeps: what a real clock does, and what FrozenClock does not. */
final class SleepingClock implements ClockInterface
{
    private float $now = 1_700_000_000.0;

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(\sprintf('@%.6F', $this->now));
    }

    public function sleep(int $microseconds): void
    {
        $this->now += $microseconds / 1_000_000;
    }
}

final class NoopReader implements SubjectReaderInterface
{
    public function supports(object $subject): bool
    {
        return false;
    }

    public function has(object $subject, string $accessor): bool
    {
        return false;
    }

    public function read(object $subject, string $accessor): mixed
    {
        return null;
    }
}

final class UninitializedSubject
{
    public string $title;
}

/** The fixes of the 0.10 audit that guard the graph (docs/plans/audit-0.10.md: A1, A5–A8, R11–R13, W7, L3). */
final class WaveGGuardsTest extends TestCase
{
    #[TestDox('A1: the facade reads with the extractor of the resolver it was given, so the change handler and explain agree with it')]
    public function testFacadeDerivesTheExtractorFromTheResolver(): void
    {
        $extractor = new ParamExtractor(new NoopReader());
        $kit = IndexNowKit::create(Factory::config(), new FakeTransport(), resolver: new AttributeUrlResolver(new AttributeReader(), $extractor));

        self::assertSame($extractor, $kit->extractor);
        self::assertSame([], (new ParamExtractor())->readers());
    }

    #[TestDox('A5: a closure node may return null for a nullable node; A6: events may be a closure; A7/A8: changes and clock are nodes')]
    public function testGraphNodes(): void
    {
        $clock = new FrozenClock();
        $events = new class implements EventDispatcherInterface {
            public int $built = 0;

            public function dispatch(object $event): object
            {
                return $event;
            }
        };
        $eventsBuilt = 0;
        $services = (new ServicesBuilder(Factory::config()))
            ->transport(new FakeTransport())
            ->submissionStore(static fn(): null => null)
            ->events(static function () use ($events, &$eventsBuilt): EventDispatcherInterface {
                ++$eventsBuilt;
                return $events;
            })
            ->clock($clock)
            ->build();

        self::assertNull($services->submissionStore(), 'a nullable node built by a closure may say "none"');
        self::assertSame(0, $eventsBuilt, 'the dispatcher is not built before it is needed');
        self::assertSame($events, $services->events());
        self::assertSame($events, $services->events(), 'built once');
        self::assertSame(1, $eventsBuilt);
        self::assertSame($clock, $services->clock());
        self::assertInstanceOf(ObjectChangeHandler::class, $services->changes());
        self::assertSame($services->changes(), $services->kit()->changes(), 'the facade shares the change handler of the graph');
    }

    #[TestDox('A5: a closure returning null for a non-nullable node is still an error')]
    public function testNullFromANonNullableNodeIsAnError(): void
    {
        $services = (new ServicesBuilder(Factory::config()))->transport(new FakeTransport())->throttle(static fn(): null => null)->build();

        $this->expectException(LogicException::class);
        $services->throttle();
    }

    #[TestDox('A7: ObserverHelper over a change handler alone hands URLs to the sink and never touches a facade')]
    public function testObserverHelperOverAChangeHandler(): void
    {
        $delivered = [];
        $helper = ObserverHelper::forChanges(new ObjectChangeHandler(new AttributeReader(), (new ServicesBuilder(Factory::config()))->transport(new FakeTransport())->build()->guardedResolver(), ParamExtractor::plain()), static function (array $urls) use (&$delivered): void {
            $delivered = $urls;
        });
        $helper->deliver(['https://www.example.com/a']);

        self::assertSame(['https://www.example.com/a'], $delivered);
        $this->expectException(LogicException::class);
        new ObserverHelper(new ObjectChangeHandler(new AttributeReader(), (new ServicesBuilder(Factory::config()))->transport(new FakeTransport())->build()->guardedResolver(), ParamExtractor::plain()));
    }

    #[TestDox('R11: with a clock that moves while the bucket sleeps, the wait is not credited twice')]
    public function testTokenBucketDoesNotDoubleCountTheSleep(): void
    {
        $clock = new SleepingClock();
        $bucket = new TokenBucket(2, $clock, $clock->sleep(...));

        $bucket->acquire();
        $bucket->acquire();
        $before = $clock->now()->format('U.u');
        $bucket->acquire(); // sleeps 30 s for one token
        self::assertEqualsWithDelta(30.0, (float) $clock->now()->format('U.u') - (float) $before, 0.01);
        $before = $clock->now()->format('U.u');
        $bucket->acquire(); // the bucket is empty again: another 30 s, not a free request
        self::assertEqualsWithDelta(30.0, (float) $clock->now()->format('U.u') - (float) $before, 0.01, 'four requests take 60 s at 2/min');
    }

    #[TestDox('R12: a sink that throws on commit is one error line, not an exception out of the database commit')]
    public function testStagingCommitSwallowsASinkFailure(): void
    {
        $logger = new ArrayLogger();
        $staging = new TransactionStaging(static function (): void {
            throw new RuntimeException('collector down');
        }, $logger);
        $scope = new stdClass();
        $staging->stage($scope, ['https://www.example.com/a']);

        $staging->commit($scope);

        self::assertCount(1, $logger->messages('error'));
        self::assertStringContainsString('collector down', $logger->messages('error')[0]);
        self::assertFalse($staging->hasPending($scope));
    }

    #[TestDox('R13: reset() reads the shared counter once per streak, not once per successful batch')]
    public function testForbiddenCounterResetReadsOnce(): void
    {
        $cache = new class extends GeneratorCache {
            public int $reads = 0;

            public function get($key, $default = null): mixed
            {
                ++$this->reads;

                return parent::get($key, $default);
            }
        };
        $counter = new ForbiddenCounter($cache, 'p_', 3, 60);
        $counter->reset('h');
        $counter->reset('h');
        $counter->reset('h');
        self::assertSame(1, $cache->reads, 'known clean after the first look');
        $counter->hit('h');
        $counter->reset('h');
        self::assertGreaterThan(1, $cache->reads, 'a 403 in between makes the next reset look again');
        self::assertSame(0, $counter->count('h'));
    }

    #[TestDox('L10: without a cache the escalation happens once per streak at or above the threshold, as with a cache')]
    public function testInProcessEscalationOncePerStreak(): void
    {
        $counter = new ForbiddenCounter(null, 'p_', 2);
        self::assertSame([1, false], $counter->hit('h'));
        self::assertSame([2, true], $counter->hit('h'));
        self::assertSame([3, false], $counter->hit('h'));
        $counter->reset('h');
        self::assertSame([1, false], $counter->hit('h'));
        self::assertSame([2, true], $counter->hit('h'), 'a new streak escalates again');
    }

    #[TestDox('W7: a declared but uninitialized typed property is "no property", not a raw Error')]
    public function testUninitializedPropertyIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('no initialized property "title"');
        (new ParamExtractor())->read(new UninitializedSubject(), 'title');
    }

    #[TestDox('L3: several Retry-After headers joined into one line: the longest wait wins')]
    public function testSeveralRetryAfterHeaders(): void
    {
        self::assertSame(120, Response::parseRetryAfter('120, 60'));
        self::assertSame(60, Response::parseRetryAfter('abc, 60'));
        self::assertNull(Response::parseRetryAfter('abc, def'));
    }
}
