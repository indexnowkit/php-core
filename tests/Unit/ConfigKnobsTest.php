<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Options added by the configurability audit: production environments, URL length, per-host engines, previous
 * keys, log levels and sampling, retry, resolver, collector and debounce prefix.
 */
final class ConfigKnobsTest extends TestCase
{
    public function testProductionEnvironmentsDriveIsProductionAndTheDryRunSafetyNet(): void
    {
        $live = Config::fromArray(['key' => Factory::KEY, 'environment' => 'live', 'production_environments' => ['Live', 'prod']]);
        self::assertTrue($live->isProduction());
        self::assertSame(['live', 'prod'], $live->productionEnvironments);
        self::assertFalse(Config::fromArray(['key' => Factory::KEY, 'environment' => 'prod', 'production_environments' => 'live'])->isProduction(), 'the default list is replaced, not extended');
    }

    public function testMissingKeyInACustomProductionEnvironmentThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        Config::fromArray(['environment' => 'live', 'production_environments' => ['live'], 'dry_run' => false, 'enabled' => true, 'key' => null, 'hosts' => []]);
    }

    public function testMissingKeyOutsideProductionSwitchesDryRunOn(): void
    {
        self::assertTrue(Config::fromArray(['environment' => 'staging', 'production_environments' => ['live']])->dryRun);
        self::assertFalse(Config::fromArray(['environment' => 'live', 'key' => Factory::KEY, 'production_environments' => ['live']])->dryRun);
    }

    public function testEmptyProductionEnvironmentsIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('production_environments');
        Config::fromArray(['key' => Factory::KEY, 'production_environments' => []]);
    }

    public function testPerHostEnginesAndPreviousKeys(): void
    {
        $config = Factory::config([
            'previous_key' => 'oldkey1234567890',
            'hosts' => ['example.ru' => ['key' => 'ru1234567890', 'engines' => ['yandex'], 'previous_key' => 'ruold1234567890']],
        ]);

        self::assertSame(['https://yandex.com/indexnow'], $config->endpointsFor('EXAMPLE.ru'));
        self::assertSame(['https://api.indexnow.org/indexnow'], $config->endpointsFor('www.example.com'));
        self::assertSame(['example.ru' => ['yandex']], $config->hostEngines);
        self::assertSame(['example.ru' => 'ruold1234567890'], $config->previousKeys);
        self::assertSame('oldkey1234567890', $config->previousKey);

        $copy = $config->with(dryRun: true);
        self::assertSame($config->hostEngines, $copy->hostEngines, 'with() carries per-host engines');
        self::assertSame($config->previousKeys, $copy->previousKeys);

        $keys = StaticKeyProvider::fromConfig($config);
        self::assertSame('ru1234567890', $keys->keyFor('example.ru'));
        self::assertTrue($keys->isKnownKey('ruold1234567890', 'example.ru'), 'the previous key is still known for its host');
        self::assertTrue($keys->isKnownKey('oldkey1234567890', 'www.example.com'));
        self::assertFalse($keys->isKnownKey('oldkey1234567890', 'example.ru'), 'the default previous key does not apply to a mapped host');
        self::assertTrue($keys->isKnownKey('ruold1234567890'));
        self::assertSame('oldkey1234567890', $keys->previousKeyFor('www.example.com'));
    }

    public function testPerHostEnginesMustNotBeEmpty(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('hosts.example.ru.engines');
        Factory::config(['hosts' => ['example.ru' => ['key' => 'ru1234567890', 'engines' => []]]]);
    }

    public function testLogLevelsOverrideTheShippedOnesAndAreValidated(): void
    {
        $config = Factory::config(['logging' => ['levels' => ['debounced' => 'INFO', 'rate_limited' => 'error'], 'max_urls' => 2, 'forbidden_escalation' => 2]]);

        self::assertSame('info', $config->logLevel('debounced'));
        self::assertSame('error', $config->logLevel('rate_limited'));
        self::assertSame('debug', $config->logLevel('ok'), 'untouched events keep their default');
        self::assertSame(['a', 'b'], $config->logSample(['a', 'b', 'c']));
        self::assertSame(2, $config->forbiddenEscalation);

        try {
            Factory::config(['logging' => ['levels' => ['nope' => 'info']]]);
            self::fail('unknown event');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('unknown event "nope"', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('logging.levels.ok');
        Factory::config(['logging' => ['levels' => ['ok' => 'loud']]]);
    }

    public function testRetryResolverCollectorAndDebouncePrefixOptions(): void
    {
        $config = Factory::config([
            'max_url_length' => 4096,
            'retry' => ['max_attempts' => 5, 'base_delay' => 10, 'multiplier' => 1.5, 'max_delay' => 100, 'server_error_delay' => 1],
            'resolver' => ['max_via_depth' => 5, 'max_via_fanout' => 500],
            'collector' => ['max_urls' => 1000, 'detect_leaks' => false],
            'debounce' => ['per_url' => 0, 'key_prefix' => 'shop_a_'],
        ]);

        self::assertSame(4096, $config->maxUrlLength);
        $policy = $config->retryPolicy();
        self::assertSame(5, $policy->maxAttempts);
        self::assertSame(10, $policy->baseDelay);
        self::assertSame(1.5, $policy->multiplier);
        self::assertSame(100, $policy->maxDelay);
        self::assertSame(1, $policy->serverErrorDelay);
        self::assertSame(5, $config->resolverMaxViaDepth);
        self::assertSame(500, $config->resolverMaxViaFanout);
        self::assertSame(1000, $config->collectorMaxUrls);
        self::assertFalse($config->collectorDetectLeaks);
        self::assertSame('shop_a_', $config->debounceKeyPrefix);
        self::assertSame([], Config::unknownOptions(['retry' => ['max_attempts' => 1], 'logging' => ['levels' => []], 'resolver' => ['max_via_depth' => 1], 'collector' => ['max_urls' => 1], 'production_environments' => ['x'], 'max_url_length' => 100, 'previous_key' => 'x']));
    }

    public function testEngineAliasesLocaleHostsAndLogBody(): void
    {
        $config = Factory::config([
            'engine_aliases' => ['Corp' => 'https://index.corp.example/indexnow'],
            'engines' => ['api', 'corp'],
            'locale_hosts' => ['EN' => 'www.example.com', 'de' => 'Example.DE'],
            'logging' => ['max_body' => 10],
            'hosts' => ['example.de' => ['key' => 'de1234567890', 'engines' => ['corp']]],
        ]);

        self::assertSame(['https://api.indexnow.org/indexnow', 'https://index.corp.example/indexnow'], $config->endpoints);
        self::assertSame(['https://index.corp.example/indexnow'], $config->endpointsFor('example.de'));
        self::assertSame(['en' => 'www.example.com', 'de' => 'example.de'], $config->localeHosts);
        self::assertSame('example.de', $config->hostForLocale('DE'));
        self::assertNull($config->hostForLocale('fr'));
        self::assertSame(10, $config->logBody);
        self::assertSame($config->engineAliases, $config->with(dryRun: true)->engineAliases);

        try {
            Factory::config(['engine_aliases' => ['yandex' => 'https://x.example/indexnow']]);
            self::fail('built-in names cannot be aliased');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('engine_aliases', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        Factory::config(['locale_hosts' => ['en' => 'https://www.example.com']]);
    }

    public function testFromEnvReadsTheNewOptions(): void
    {
        $config = Config::fromEnv([
            'INDEXNOW_KEY' => Factory::KEY,
            'INDEXNOW_PRODUCTION_ENVIRONMENTS' => 'live, prd',
            'INDEXNOW_MAX_URL_LENGTH' => '3000',
            'INDEXNOW_LOG_URLS' => '0',
            'INDEXNOW_FORBIDDEN_ESCALATION' => '3',
            'INDEXNOW_RETRY_MAX_ATTEMPTS' => '4',
            'APP_ENV' => 'prd',
        ]);

        self::assertSame(['live', 'prd'], $config->productionEnvironments);
        self::assertTrue($config->isProduction());
        self::assertSame(3000, $config->maxUrlLength);
        self::assertSame([], $config->logSample(['a']));
        self::assertSame(3, $config->forbiddenEscalation);
        self::assertSame(4, $config->retryMaxAttempts);
    }

    public function testInvalidDebouncePrefixAndResolverBoundsAreRejected(): void
    {
        try {
            Factory::config(['debounce' => ['per_url' => 0, 'key_prefix' => 'bad prefix']]);
            self::fail('prefix');
        } catch (ConfigurationException $e) {
            self::assertSame('"debounce.key_prefix" must be a non-empty string without the characters PSR-6 reserves in cache keys ({}()/\\@:) or whitespace, got "bad prefix". Letters, digits, "_", "-" and "." are safe.', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"resolver.max_via_fanout" must be >= 1 (related objects one `via:` hop may yield), got 0.');
        Factory::config(['resolver' => ['max_via_fanout' => 0]]);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function boundsProvider(): iterable
    {
        yield 'via depth' => [['resolver' => ['max_via_depth' => -1]], '"resolver.max_via_depth" must be >= 0 (0 = rules may not follow `via:` at all), got -1.'];
        yield 'retry attempts' => [['retry' => ['max_attempts' => 0]], '"retry.max_attempts" must be >= 1 (the first attempt counts; 1 = never retry), got 0.'];
        yield 'retry base delay' => [['retry' => ['base_delay' => -5]], '"retry.base_delay" must be >= 0 seconds (the wait before the second attempt after a 429), got -5.'];
        yield 'retry multiplier' => [['retry' => ['multiplier' => 0.5]], '"retry.multiplier" must be >= 1.0 (each further wait is the previous one times this), got 0.5.'];
        yield 'retry max delay' => [['retry' => ['max_delay' => -1]], '"retry.max_delay" must be >= 0 seconds (the ceiling of the growing wait), got -1.'];
        yield 'retry server error delay' => [['retry' => ['server_error_delay' => -1]], '"retry.server_error_delay" must be >= 0 seconds (the wait after a 5xx or a network failure), got -1.'];
        yield 'engine' => [['engines' => ['gogle']], 'Unknown IndexNow engine "gogle". Use one of: api, yandex, bing, naver, seznam, yep, internetarchive, amazon, an alias from engine_aliases, or a full https endpoint URL.'];
    }

    #[DataProvider('boundsProvider')]
    public function testEachBoundHasItsOwnMessage(array $overrides, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);
        Factory::config($overrides);
    }
}
