<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Adapter;

use Closure;
use IndexNowKit\Adapter\Services;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Adapter\SubmitterFactory;
use IndexNowKit\Attribute\AttributeReader;
use IndexNowKit\Attribute\RuleRegistry;
use IndexNowKit\Check\Checker;
use IndexNowKit\Client;
use IndexNowKit\Collector\Collector;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreFactory;
use IndexNowKit\Dispatch\DispatcherFactory;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Submitter;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Url\AttributeUrlResolver;
use IndexNowKit\Url\GuardedUrlResolver;
use IndexNowKit\Url\UrlNormalizer;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionObject;
use SplObjectStorage;

/**
 * Layer 2 (`Adapter\Services`) is a memoization over layer 1 (the factories of docs/spec/16 §3.1): for every public
 * accessor of `Services` there is a factory or constructor, and the object it returns is configured exactly like
 * the one built by hand. This test is the guard against the two layers drifting apart: a new accessor without a
 * hand-built twin fails it.
 */
final class ServicesParityTest extends TestCase
{
    /** Accessors that are not graph nodes: state queries and the request-end hook, tested in ServicesBuilderTest. */
    private const NOT_NODES = ['hasCollected', 'flushIfCollected'];

    #[TestDox('every public accessor of Services returns what the corresponding factory or constructor builds')]
    public function testParity(): void
    {
        $config = Factory::config([
            'throttle' => ['max_requests_per_minute' => 7],
            'resolver' => ['max_via_depth' => 5, 'max_via_fanout' => 9],
            'locale_hosts' => ['de' => 'de.example.com'],
            'collector' => ['detect_leaks' => false, 'max_urls' => 42],
            'debounce' => ['per_url' => 30, 'key_prefix' => 'parity'],
            'key_file' => ['cache_max_age' => 60],
            'logging' => ['max_urls' => 3],
            'hosts' => ['www.example.com' => Factory::KEY, 'de.example.com' => 'abcdefgh12345678'],
            'strict_hosts' => true,
        ]);
        $transport = new FakeTransport();
        $logger = new ArrayLogger();

        $services = (new ServicesBuilder($config, $logger))->transport($transport)->build();
        $byHand = self::byHand($config, $transport, $logger);

        $covered = [];
        foreach ($byHand as $accessor => $expected) {
            $covered[] = $accessor;
            self::assertSameGraph($expected, $services->{$accessor}(), $accessor);
        }

        $public = array_map(static fn(ReflectionMethod $m): string => $m->getName(), array_filter((new ReflectionClass(Services::class))->getMethods(ReflectionMethod::IS_PUBLIC), static fn(ReflectionMethod $m): bool => !$m->isStatic() && !$m->isConstructor()));
        self::assertSame([], array_values(array_diff($public, $covered, self::NOT_NODES)), 'every public accessor of Services has a hand-built twin in this test');
        self::assertSame([], array_values(array_diff($covered, $public)), 'every twin names an accessor');
    }

    /**
     * The graph an adapter on layer 1 builds by hand, one entry per Services accessor.
     *
     * @return array<string, object|null> null for a node that is absent by default
     */
    private static function byHand(Config $config, FakeTransport $transport, ArrayLogger $logger): array
    {
        $keys = StaticKeyProvider::fromConfig($config);
        $normalizer = new UrlNormalizer($config->baseUrl, $config->maxUrlLength);
        $throttle = TokenBucket::fromConfig($config, $logger);
        $debounce = DebounceStoreFactory::fromConfig($config);
        $client = new Client($transport, $keys, $config, $logger, $throttle, $normalizer);
        $submitter = new Submitter($client, $config, $debounce, $logger, $normalizer);
        $collector = Collector::fromConfig($config, $logger);
        $dispatcher = DispatcherFactory::fromConfig($config, $submitter, $logger);
        $rules = new RuleRegistry(new AttributeReader());
        $urlResolver = AttributeUrlResolver::fromConfig($config, $rules, null, null, $logger);
        $guarded = new GuardedUrlResolver($urlResolver, $rules, $logger);
        $kit = new IndexNowKit($config, $submitter, $collector, $dispatcher, $keys, $rules, $guarded, $logger, $transport);

        return [
            'transport' => $transport,
            'keys' => $keys,
            'normalizer' => $normalizer,
            'throttle' => $throttle,
            'debounceStore' => $debounce,
            'client' => $client,
            'submitter' => $submitter,
            'collector' => $collector,
            'dispatcher' => $dispatcher,
            'reader' => $rules,
            'rules' => $rules,
            'router' => null,
            'resolverLocator' => null,
            'urlResolver' => $urlResolver,
            'guardedResolver' => $guarded,
            'changes' => $kit->changes(),
            'kit' => $kit,
            'keyFileResponder' => KeyFileResponder::fromConfig($config, $keys),
            'checker' => new Checker($config, $keys, $transport, []),
            'submitterFactory' => new SubmitterFactory($transport, $keys, $config, $debounce, $throttle, $normalizer, $logger),
        ];
    }

    private static function assertSameGraph(?object $expected, ?object $actual, string $accessor): void
    {
        if ($expected === null) {
            self::assertNull($actual, $accessor . '(): none by default');

            return;
        }
        self::assertNotNull($actual, $accessor);
        self::assertSame($expected::class, $actual::class, $accessor . '(): same class');
        self::assertSame(self::export($expected, new SplObjectStorage()), self::export($actual, new SplObjectStorage()), $accessor . '(): same configuration');
    }

    /**
     * Every property, recursively, with closures and clock readings replaced by placeholders: the shape of the
     * object as the factories configured it.
     *
     * @param SplObjectStorage<object, int> $seen
     */
    private static function export(mixed $value, SplObjectStorage $seen): mixed
    {
        if ($value instanceof Closure) {
            return '(closure)';
        }
        if (\is_array($value)) {
            return array_map(static fn(mixed $v): mixed => self::export($v, $seen), $value);
        }
        if (!\is_object($value)) {
            return $value;
        }
        if ($seen->contains($value)) {
            return '(seen #' . $seen[$value] . ')';
        }
        $seen[$value] = \count($seen);
        $out = ['@class' => $value::class];
        $ref = new ReflectionObject($value);
        do {
            foreach ($ref->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $ref->getName()) {
                    continue;
                }
                if (!$property->isInitialized($value)) {
                    $out[$property->getName()] = '(uninitialized)';

                    continue;
                }
                $name = $property->getName();
                // TokenBucket reads the clock in its constructor; the reading is state, not configuration.
                $out[$name] = \in_array($name, ['lastRefill', 'tokens'], true) && $value instanceof TokenBucket ? '(clock)' : self::export($property->getValue($value), $seen);
            }
        } while (($ref = $ref->getParentClass()) !== false);

        return $out;
    }
}
