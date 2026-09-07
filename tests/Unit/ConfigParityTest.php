<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Adapter\ConfigFactory;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The observable behaviour of `Config` pinned to a snapshot (tests/fixtures/config-parity.json): for a corpus of
 * configurations — every option, both builders, the constructor, `with()`, the derived methods — the public properties and
 * `toArray()`; for a corpus of invalid ones the exception class and message. Taken on 9232abc, before the parsing and
 * normalisation moved out of `Config.php` (audit 0.13 W11, docs/plans/config-decomposition.md), and kept as the regression
 * guard of that surface: a difference here is either a bug or a deliberate change, and the latter rewrites the snapshot
 * on purpose with `INDEXNOWKIT_WRITE_SNAPSHOT=1`.
 */
final class ConfigParityTest extends TestCase
{
    private const SNAPSHOT = __DIR__ . '/../fixtures/config-parity.json';

    /**
     * @return array<string, Config>
     */
    private static function validCorpus(): array
    {
        $full = [
            'enabled' => true, 'key' => 'abcdefgh12345678', 'previous_key' => 'oldkeyoldkey1234',
            'hosts' => [
                'www.example.com' => 'hostkeyhostkey12',
                'Shop.Example.COM' => ['key' => 'shopkeyshopkey12', 'key_location' => 'https://shop.example.com/keys/shop.txt', 'base_url' => 'https://shop.example.com/', 'engines' => ['yandex', 'corp'], 'previous_key' => 'shopoldshopold12'],
                '[2001:db8::1]' => ['key' => 'ipv6keyipv6key12'],
            ],
            'key_location' => 'https://www.example.com/abcdefgh12345678.txt', 'base_url' => 'https://www.example.com', 'strict_hosts' => true,
            'engines' => ['api', 'https://index.corp.example/indexnow', 'corp'], 'engine_aliases' => ['corp' => 'https://index.corp.example/indexnow', 'Other' => 'https://other.example/x'],
            'locale_hosts' => ['EN' => 'www.example.com', 'de' => 'Example.DE'],
            'dispatch' => 'queue', 'dry_run' => false, 'environment' => 'Staging', 'production_environments' => ['Prod', ' live ', 'prod'],
            'max_url_length' => 1024, 'key_file' => ['enabled' => false, 'cache_max_age' => 0],
            'batch' => ['max_urls' => 500], 'debounce' => ['per_url' => 0, 'key_prefix' => 'app-1.', 'store' => 'cache.app'],
            'throttle' => ['max_requests_per_minute' => 0], 'http' => ['timeout' => 2.5, 'user_agent' => 'my-agent/1.0', 'client' => 'App\Http\Client'],
            'logging' => ['max_urls' => 0, 'forbidden_escalation' => 1, 'levels' => ['ok' => 'INFO', 'no_key' => 'error'], 'max_body' => 0],
            'retry' => ['max_attempts' => 1, 'base_delay' => 0, 'multiplier' => 1.0, 'max_delay' => 0, 'server_error_delay' => 0],
            'resolver' => ['max_via_depth' => 0, 'max_via_fanout' => 1], 'collector' => ['max_urls' => 10, 'detect_leaks' => false],
            'normalizer' => ['strip_tracking_params' => false, 'tracking_params' => ['REF', 'mtm_*', 'ref'], 'trailing_slash' => 'add', 'sort_query' => true],
        ];
        $strings = [
            'enabled' => 'yes', 'key' => 'abcdefgh', 'dry_run' => 'false', 'strict_hosts' => '0', 'base_url' => 'https://a.example',
            'max_url_length' => '128', 'batch' => ['max_urls' => '+7'], 'debounce' => ['per_url' => '60'], 'throttle' => ['max_requests_per_minute' => '5'],
            'http' => ['timeout' => '1.5'], 'logging' => ['max_urls' => '3', 'forbidden_escalation' => '2', 'max_body' => '10'],
            'retry' => ['max_attempts' => '2', 'base_delay' => '1', 'multiplier' => '3', 'max_delay' => '4', 'server_error_delay' => '5'],
            'resolver' => ['max_via_depth' => '1', 'max_via_fanout' => '2'], 'collector' => ['max_urls' => '1', 'detect_leaks' => 'off'],
            'key_file' => ['cache_max_age' => '30'], 'normalizer' => ['strip_tracking_params' => 'no', 'sort_query' => 'on', 'tracking_params' => 'a, b ,'],
            'production_environments' => 'prod, live', 'engines' => ['bing'],
        ];
        $env = [
            'INDEXNOW_ENABLED' => 'true', 'INDEXNOW_KEY' => 'envkeyenvkey1234', 'INDEXNOW_PREVIOUS_KEY' => 'envoldenvold1234',
            'INDEXNOW_HOSTS' => 'a.example=aaaaaaaa1234, b.example = bbbbbbbb1234 ,', 'INDEXNOW_KEY_LOCATION' => 'https://env.example/k.txt',
            'INDEXNOW_BASE_URL' => 'https://env.example', 'INDEXNOW_ENGINES' => 'yandex, bing', 'INDEXNOW_DISPATCH' => 'none',
            'INDEXNOW_BATCH_MAX_URLS' => '9', 'INDEXNOW_DEBOUNCE_PER_URL' => '8', 'INDEXNOW_DEBOUNCE_STORE' => 'memory',
            'INDEXNOW_THROTTLE_PER_MINUTE' => '7', 'INDEXNOW_HTTP_TIMEOUT' => '6.5', 'INDEXNOW_USER_AGENT' => 'env-agent', 'INDEXNOW_HTTP_CLIENT' => 'App\Client',
            'INDEXNOW_SERVE_KEY_FILE' => 'false', 'INDEXNOW_KEY_FILE_ENABLED' => 'true', 'INDEXNOW_KEY_FILE_CACHE_MAX_AGE' => '5',
            'INDEXNOW_DRY_RUN' => '0', 'INDEXNOW_STRICT_HOSTS' => 'yes', 'INDEXNOW_MAX_URL_LENGTH' => '256',
            'INDEXNOW_PRODUCTION_ENVIRONMENTS' => 'prod,live', 'INDEXNOW_LOG_URLS' => '4', 'INDEXNOW_FORBIDDEN_ESCALATION' => '3',
            'INDEXNOW_RETRY_MAX_ATTEMPTS' => '2', 'INDEXNOW_RETRY_BASE_DELAY' => '1', 'INDEXNOW_RETRY_MULTIPLIER' => '1.5', 'INDEXNOW_RETRY_MAX_DELAY' => '9', 'INDEXNOW_RETRY_SERVER_ERROR_DELAY' => '2',
            'INDEXNOW_ENV' => 'prod', 'APP_ENV' => 'dev', 'UNRELATED' => 'x', 'INDEXNOW_UNKNOWN' => 'ignored',
        ];
        $base = Config::fromArray(['key' => 'abcdefgh', 'base_url' => 'https://www.example.com', 'engines' => ['yandex']]);

        return [
            'minimal' => Config::fromArray(['key' => 'abcdefgh']),
            'no key outside production' => Config::fromArray(['environment' => 'dev']),
            'no key, disabled' => Config::fromArray(['enabled' => false]),
            'no key, dry run' => Config::fromArray(['dry_run' => true]),
            'hosts map without default key' => Config::fromArray(['hosts' => ['www.example.com' => 'hostkeyhostkey12']]),
            'full' => Config::fromArray($full),
            'scalar strings' => Config::fromArray($strings),
            'serve_key_file legacy wins' => Config::fromArray(['key' => 'abcdefgh', 'serve_key_file' => false, 'key_file' => ['enabled' => true]]),
            'key_file.enabled' => Config::fromArray(['key' => 'abcdefgh', 'key_file' => ['enabled' => 'false']]),
            'empty strings are unset' => Config::fromArray(['key' => 'abcdefgh', 'base_url' => '', 'dry_run' => '', 'environment' => '', 'http' => ['timeout' => ''], 'debounce' => ['store' => null]]),
            'non-array blocks ignored' => Config::fromArray(['key' => 'abcdefgh', 'retry' => 'x', 'logging' => ['levels' => 'x'], 'engine_aliases' => 5, 'locale_hosts' => 'x', 'hosts' => 'x', 'engines' => 'yandex']),
            'idn base_url' => Config::fromArray(['key' => 'abcdefgh', 'base_url' => 'https://Пример.рф/path', 'strict_hosts' => true]),
            'env full' => Config::fromEnv($env),
            'env minimal APP_ENV' => Config::fromEnv(['APP_ENV' => 'dev']),
            'env custom prefix' => Config::fromEnv(['IN_KEY' => 'abcdefgh', 'IN_DRY_RUN' => 'true', 'INDEXNOW_KEY' => 'ignored1'], 'IN_'),
            'constructor defaults' => new Config(key: 'abcdefgh'),
            'constructor hosts' => new Config(enabled: true, hosts: ['a.example' => ['key' => 'abcdefgh12', 'engines' => ['bing']]], engines: ['yandex'], logLevels: ['ok' => 'debug'], engineAliases: ['x' => 'https://x.example/i'], localeHosts: ['fr' => 'fr.example'], productionEnvironments: ['Live'], normalizerTrackingParams: ['p*'], dryRunExplicit: false),
            'disabled' => ConfigFactory::disabled('dev'),
            'with dry run' => $base->with(dryRun: true),
            'with several' => $base->with(engines: ['api', 'bing'], hosts: ['b.example' => 'bbbbbbbb1234'], retryMaxAttempts: 5, normalizerTrailingSlash: 'strip', logLevels: ['ok' => 'notice']),
            'withDryRun false' => Config::fromArray(['key' => 'abcdefgh', 'environment' => 'dev'])->withDryRun(false),
            'with keeps explicit flag' => Config::fromArray(['key' => 'abcdefgh', 'dry_run' => true])->with(engines: ['bing']),
            'round trip full' => Config::fromArray(Config::fromArray($full)->toArray()),
            'round trip env' => Config::fromArray(Config::fromEnv($env)->toArray()),
        ];
    }

    /**
     * @return array<string, callable(): mixed>
     */
    private static function invalidCorpus(): array
    {
        $k = ['key' => 'abcdefgh'];
        $cases = [
            'trailing_slash' => $k + ['normalizer' => ['trailing_slash' => 'both']],
            'logging.max_body' => $k + ['logging' => ['max_body' => -1]],
            'key_file.cache_max_age' => $k + ['key_file' => ['cache_max_age' => -1]],
            'engine alias name' => $k + ['engine_aliases' => ['1bad' => 'https://x.example']],
            'engine alias builtin' => $k + ['engine_aliases' => ['Yandex' => 'https://x.example']],
            'engine alias endpoint' => $k + ['engine_aliases' => ['corp' => 'ftp://x.example']],
            'locale host' => $k + ['locale_hosts' => ['en' => 'https://x.example']],
            'locale empty' => $k + ['locale_hosts' => ['' => 'x.example']],
            'previous_key' => $k + ['previous_key' => 'short'],
            'logging.levels event' => $k + ['logging' => ['levels' => ['nope' => 'info']]],
            'logging.levels level' => $k + ['logging' => ['levels' => ['ok' => 'loud']]],
            'resolver.max_via_depth' => $k + ['resolver' => ['max_via_depth' => -1]],
            'resolver.max_via_fanout' => $k + ['resolver' => ['max_via_fanout' => 0]],
            'collector.max_urls' => $k + ['collector' => ['max_urls' => -1]],
            'debounce.key_prefix reserved' => $k + ['debounce' => ['key_prefix' => 'a:b']],
            'production_environments empty' => $k + ['production_environments' => [' ']],
            'max_url_length' => $k + ['max_url_length' => 63],
            'logging.max_urls' => $k + ['logging' => ['max_urls' => -1]],
            'logging.forbidden_escalation' => $k + ['logging' => ['forbidden_escalation' => 0]],
            'retry.max_attempts' => $k + ['retry' => ['max_attempts' => 0]],
            'retry.base_delay' => $k + ['retry' => ['base_delay' => -1]],
            'retry.multiplier' => $k + ['retry' => ['multiplier' => 0.5]],
            'retry.max_delay' => $k + ['retry' => ['max_delay' => -1]],
            'retry.server_error_delay' => $k + ['retry' => ['server_error_delay' => -1]],
            'no key in production' => ['environment' => 'prod'],
            'no key, no environment' => [],
            'key invalid' => ['key' => 'no spaces allowed'],
            'hosts name' => $k + ['hosts' => ['https://x.example' => 'abcdefgh12']],
            'hosts entry' => $k + ['hosts' => ['x.example' => ['key' => 5]]],
            'hosts key invalid' => $k + ['hosts' => ['x.example' => 'bad key']],
            'hosts key_location url' => $k + ['hosts' => ['x.example' => ['key' => 'abcdefgh12', 'key_location' => 'nope']]],
            'hosts key_location host' => $k + ['hosts' => ['x.example' => ['key' => 'abcdefgh12', 'key_location' => 'https://y.example/k.txt']]],
            'hosts base_url url' => $k + ['hosts' => ['x.example' => ['key' => 'abcdefgh12', 'base_url' => 'x']]],
            'hosts base_url host' => $k + ['hosts' => ['x.example' => ['key' => 'abcdefgh12', 'base_url' => 'https://y.example']]],
            'hosts engines empty' => $k + ['hosts' => ['x.example' => ['key' => 'abcdefgh12', 'engines' => []]]],
            'hosts previous_key type' => $k + ['hosts' => ['x.example' => ['key' => 'abcdefgh12', 'previous_key' => 7]]],
            'hosts previous_key invalid' => $k + ['hosts' => ['x.example' => ['key' => 'abcdefgh12', 'previous_key' => 'x']]],
            'base_url' => $k + ['base_url' => 'www.example.com'],
            'base_url userinfo' => $k + ['base_url' => 'https://user:pw@www.example.com'],
            'key_location path' => $k + ['key_location' => 'https://www.example.com/'],
            'key_location host' => $k + ['key_location' => 'https://a.example/k.txt', 'base_url' => 'https://b.example'],
            'batch.max_urls low' => $k + ['batch' => ['max_urls' => 0]],
            'batch.max_urls high' => $k + ['batch' => ['max_urls' => 10001]],
            'debounce.per_url' => $k + ['debounce' => ['per_url' => -1]],
            'throttle' => $k + ['throttle' => ['max_requests_per_minute' => -1]],
            'http.timeout' => $k + ['http' => ['timeout' => 0]],
            'engines empty' => $k + ['engines' => []],
            'engine unknown' => $k + ['engines' => ['altavista']],
            'dispatch' => $k + ['dispatch' => 'queue now'],
            'http.user_agent' => $k + ['http' => ['user_agent' => "a\nb"]],
            'strict_hosts' => $k + ['strict_hosts' => true],
            'tracking_params' => $k + ['normalizer' => ['tracking_params' => ['a b']]],
            'int' => $k + ['batch' => ['max_urls' => '1.5']],
            'int text' => $k + ['max_url_length' => 'many'],
            'float' => $k + ['http' => ['timeout' => 'fast']],
            'bool type' => $k + ['dry_run' => ['yes']],
        ];
        $corpus = [];
        foreach ($cases as $name => $data) {
            $corpus[$name] = static fn(): Config => Config::fromArray($data);
        }
        $corpus['env hosts pair'] = static fn(): Config => Config::fromEnv(['INDEXNOW_KEY' => 'abcdefgh', 'INDEXNOW_HOSTS' => 'a.example']);
        $corpus['with unknown'] = static fn(): Config => Config::fromArray($k)->with(dry_run: true);
        $corpus['with positional'] = static fn(): Config => Config::fromArray($k)->with(true);
        $corpus['serveKeyFileFrom type'] = static fn(): bool => Config::serveKeyFileFrom(['serve_key_file' => [1]]);
        $corpus['constructor key_prefix reserved'] = static fn(): Config => new Config(key: 'abcdefgh', debounceKeyPrefix: 'x y');
        $corpus['constructor debounce.store empty'] = static fn(): Config => new Config(key: 'abcdefgh', debounceStore: '');
        $corpus['constructor http.client empty'] = static fn(): Config => new Config(key: 'abcdefgh', httpClient: '');
        $corpus['constructor key_prefix empty'] = static fn(): Config => new Config(key: 'abcdefgh', debounceKeyPrefix: '');
        $corpus['resolveEngine'] = static fn(): string => Config::fromArray($k)->resolveEngine('nope');

        return $corpus;
    }

    /**
     * @return array<string, mixed>
     */
    private static function derived(Config $config): array
    {
        $policy = $config->retryPolicy();

        return [
            'baseHost' => $config->baseHost(), 'isProduction' => $config->isProduction(), 'keyFileHeaders' => $config->keyFileHeaders(),
            'retryPolicy' => get_object_vars($policy), 'logSample' => $config->logSample(['a', 'b', 'c']),
            'baseUrlFor' => [$config->baseUrlFor('www.example.com'), $config->baseUrlFor('SHOP.example.com'), $config->baseUrlFor('a.example'), $config->baseUrlFor('env.example')],
            'endpointsFor' => [$config->endpointsFor('www.example.com'), $config->endpointsFor('shop.example.com'), $config->endpointsFor('other.example')],
            'logLevel' => [$config->logLevel('ok'), $config->logLevel('no_key'), $config->logLevel('unknown_event')],
            'hostForLocale' => [$config->hostForLocale('EN'), $config->hostForLocale('de'), $config->hostForLocale('fr'), $config->hostForLocale(null)],
            'resolveEngine' => [$config->resolveEngine('yandex'), $config->resolveEngine('https://x.example/i')],
            'userAgent' => $config->userAgent === null ? '(default)' : $config->userAgent(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function actual(): array
    {
        $valid = [];
        foreach (self::validCorpus() as $name => $config) {
            $valid[$name] = ['properties' => get_object_vars($config), 'toArray' => $config->toArray(), 'derived' => self::derived($config)];
        }
        $invalid = [];
        foreach (self::invalidCorpus() as $name => $case) {
            try {
                $case();
                $invalid[$name] = 'no exception';
            } catch (Throwable $e) {
                $invalid[$name] = [$e::class, $e->getMessage()];
            }
        }
        $statics = [
            'serveKeyFileFrom' => [Config::serveKeyFileFrom([]), Config::serveKeyFileFrom(['serve_key_file' => 'no']), Config::serveKeyFileFrom(['key_file' => ['enabled' => '0']]), Config::serveKeyFileFrom(['serve_key_file' => '', 'key_file' => ['enabled' => 'yes']])],
            'unknownOptions' => Config::unknownOptions(['key' => 'x', 'hosts' => ['a' => 'b'], 'debounce' => ['per_urls' => 1, 'store' => 'x'], 'sitemap' => ['x' => 1], 'typo' => 1, 'engines' => ['a']], ['sitemap.x']),
        ];

        return ['valid' => $valid, 'invalid' => $invalid, 'static' => $statics];
    }

    #[TestDox('Config behaves as the snapshot taken before the decomposition says: properties, toArray(), derived methods, exceptions')]
    public function testParity(): void
    {
        $actual = self::actual();
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
        if (getenv('INDEXNOWKIT_WRITE_SNAPSHOT') !== false) {
            file_put_contents(self::SNAPSHOT, $json);
            self::assertFileExists(self::SNAPSHOT);

            return;
        }
        self::assertFileExists(self::SNAPSHOT, 'run once with INDEXNOWKIT_WRITE_SNAPSHOT=1 to record the snapshot');
        /** @var array<string, array<string, mixed>> $expected */
        $expected = json_decode((string) file_get_contents(self::SNAPSHOT), true, 512, JSON_THROW_ON_ERROR);
        foreach (['valid', 'invalid', 'static'] as $section) {
            foreach ($expected[$section] as $name => $value) {
                self::assertSame($value, json_decode(json_encode($actual[$section][$name] ?? null, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true), \sprintf('%s: %s', $section, $name));
            }
            self::assertSame(array_keys($expected[$section]), array_keys($actual[$section]), $section . ': the corpus itself changed');
        }
        self::assertSame($expected, json_decode($json, true), 'whole snapshot');
    }

    #[TestDox('every valid configuration survives fromArray(toArray()) unchanged')]
    public function testToArrayRoundTrip(): void
    {
        foreach (self::validCorpus() as $name => $config) {
            $again = Config::fromArray($config->toArray());
            self::assertSame($config->toArray(), $again->toArray(), $name);
            // toArray() carries no dryRunExplicit: a re-read array has an explicit dry_run key
            self::assertSame(array_diff_key(get_object_vars($config), ['dryRunExplicit' => 0]), array_diff_key(get_object_vars($again), ['dryRunExplicit' => 0]), $name);
        }
    }

    #[TestDox('the invalid corpus really is invalid: every case throws a ConfigurationException')]
    public function testInvalidCorpusThrows(): void
    {
        foreach (self::invalidCorpus() as $name => $case) {
            try {
                $case();
                self::fail($name . ' did not throw');
            } catch (ConfigurationException $e) {
                self::assertNotSame('', $e->getMessage(), $name);
            }
        }
    }
}
