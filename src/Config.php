<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Config\ConfigNormalizer;
use IndexNowKit\Config\ConfigParser;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Url\Punycode;
use ReflectionMethod;

/**
 * Immutable configuration shared by every indexnowkit adapter. Keys mirror docs/spec/02.
 *
 * Built with {@see fromArray()} (framework config), {@see fromEnv()} (INDEXNOW_* variables) or the
 * constructor; derived copies with {@see with()}. The constructor checks the invariants; reading the array and environment
 * shapes is `Config\ConfigParser`, normalising the raw maps `Config\ConfigNormalizer` — internal, this class is the surface.
 */
final readonly class Config
{
    /** Protocol maximum of URLs per request. */
    public const MAX_BATCH_URLS = 10000;
    public const DEFAULT_BATCH_MAX_URLS = self::MAX_BATCH_URLS;
    /** Yandex accepts the same URL at most once per 10 minutes. */
    public const DEFAULT_DEBOUNCE_PER_URL = 600;
    public const DEFAULT_THROTTLE_PER_MINUTE = 60;
    public const DEFAULT_HTTP_TIMEOUT = 10.0;
    /** Default of `production_environments`: names for which the missing-key dry-run safety net is off. */
    public const PRODUCTION_ENVIRONMENTS = ['prod', 'production'];
    /** Conservative browser-era ceiling; the protocol itself sets none. */
    public const DEFAULT_MAX_URL_LENGTH = 2048;
    /** URLs listed in one log line (the count is always logged in full). */
    public const DEFAULT_LOG_URLS = 20;
    /** Consecutive 403s for one host after which the log level escalates to critical. */
    public const DEFAULT_FORBIDDEN_ESCALATION = 5;
    public const DEFAULT_RETRY_MAX_ATTEMPTS = 3;
    public const DEFAULT_RETRY_BASE_DELAY = 60;
    public const DEFAULT_RETRY_MULTIPLIER = 2.0;
    public const DEFAULT_RETRY_MAX_DELAY = 3600;
    public const DEFAULT_RETRY_SERVER_ERROR_DELAY = 5;
    public const DEFAULT_RESOLVER_MAX_VIA_DEPTH = 3;
    public const DEFAULT_RESOLVER_MAX_VIA_FANOUT = 100;
    public const DEFAULT_DEBOUNCE_KEY_PREFIX = 'indexnowkit_';
    /** Default of `normalizer.trailing_slash`: the path is submitted as the site generates it. */
    public const DEFAULT_TRAILING_SLASH = Url\CanonicalUrlNormalizer::TRAILING_SLASH_KEEP;
    public const TRAILING_SLASH_MODES = [Url\CanonicalUrlNormalizer::TRAILING_SLASH_KEEP, Url\CanonicalUrlNormalizer::TRAILING_SLASH_ADD, Url\CanonicalUrlNormalizer::TRAILING_SLASH_STRIP];
    /** Outcomes whose log level `logging.levels` may override, with the shipped level. */
    public const LOG_EVENTS = [
        'ok' => 'debug', 'pending' => 'info', 'invalid_request' => 'error', 'unprocessable' => 'warning', 'rate_limited' => 'warning',
        'server_error' => 'warning', 'unexpected' => 'error', 'transport' => 'warning', 'no_key' => 'warning', 'dry_run' => 'info',
        'disabled' => 'info', 'debounced' => 'debug', 'invalid_url' => 'warning',
    ];
    private const LOG_LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
    /** Bytes of a response body kept in a failure log line. */
    public const DEFAULT_LOG_BODY = 300;

    /** @var list<string> engine names or endpoint URLs as configured */
    public array $engines;

    /** @var list<string> resolved, de-duplicated endpoint URLs */
    public array $endpoints;

    /** @var array<string, string> host => key */
    public array $hosts;

    /** @var array<string, string> host => key file URL (per-host overrides of key_location) */
    public array $keyLocations;

    /** @var array<string, string> host => absolute base URL (per-host overrides of base_url for URL generation outside requests) */
    public array $hostBaseUrls;

    /** @var array<string, list<string>> host => engine names/URLs overriding `engines` for that host */
    public array $hostEngines;

    /** @var array<string, list<string>> host => resolved endpoints for {@see endpointsFor()} */
    private array $hostEndpoints;

    /** @var array<string, string> host => previous key, still accepted by the key file during a rotation */
    public array $previousKeys;

    /** @var array<string, string> lower-cased log event => level (validated PSR-3 level names) */
    public array $logLevels;

    /** @var array<string, string> engine alias => https endpoint, usable in `engines` and `hosts.<host>.engines` */
    public array $engineAliases;

    /** @var array<string, string> locale => host: rules with `locales` and no `host` generate each locale on its host */
    public array $localeHosts;

    /** @var list<string> lower-cased names of environments treated as production */
    public array $productionEnvironments;

    /** @var list<string> `normalizer.tracking_params`: query parameters removed on top of {@see Url\CanonicalUrlNormalizer::TRACKING_PARAMS} (`name` or `prefix*`) */
    public array $normalizerTrackingParams;

    /**
     * Whether `dry_run` was set by the configuration rather than left to its default: false when
     * {@see fromArray()} saw no `dry_run` key (or a null one). `check` tells the two apart outside
     * production: an unset dry_run there is an error, an explicit `dry_run: false` a warning.
     */
    public bool $dryRunExplicit;

    /**
     * Every key fromArray() understands, dotted-path form. Adapters validate their own config against it with
     * unknownOptions(). Nested keys are listed as `block.key` only: a bare block name would stop unknownOptions()
     * from checking the keys inside it.
     */
    public const OPTIONS = [
        'enabled', 'key', 'hosts', 'key_location', 'base_url', 'engines', 'dispatch', 'serve_key_file', 'dry_run',
        'strict_hosts', 'environment', 'production_environments', 'max_url_length', 'previous_key',
        'key_file.enabled', 'key_file.cache_max_age',
        'batch.max_urls', 'debounce.per_url', 'debounce.key_prefix', 'debounce.store', 'throttle.max_requests_per_minute',
        'http.timeout', 'http.user_agent', 'http.client',
        'logging.max_urls', 'logging.forbidden_escalation', 'logging.levels', 'logging.max_body', 'engine_aliases', 'locale_hosts',
        'retry.max_attempts', 'retry.base_delay', 'retry.multiplier', 'retry.max_delay', 'retry.server_error_delay',
        'resolver.max_via_depth', 'resolver.max_via_fanout', 'collector.max_urls', 'collector.detect_leaks',
        'normalizer.strip_tracking_params', 'normalizer.tracking_params', 'normalizer.trailing_slash', 'normalizer.sort_query',
    ];

    /**
     * @param array<string, string|array{key: string, key_location?: string|null, base_url?: string|null, engines?: list<string>|null, previous_key?: string|null}> $hosts       per-host keys for multi-site setups
     * @param list<string>                                                                                 $engines     engine names ({@see Engine}) or endpoint URLs
     * @param string                                                                                       $dispatch    adapter-defined delivery mode (sync, queue, ...); the core only reports it
     * @param bool                                                                                         $strictHosts apply the default key only to the base_url host; other hosts need a `hosts` entry
     * @param string|null                                                                                  $environment application environment (prod, dev, ...) for diagnostics; see PRODUCTION_ENVIRONMENTS
     * @param list<string>                                                                                 $productionEnvironments environment names (case-insensitive) that count as production
     * @param int                                                                                          $maxUrlLength URLs longer than this are rejected as invalid
     * @param int                                                                                          $logUrls how many URLs a log line lists
     * @param int                                                                                          $forbiddenEscalation consecutive 403s per host before the log escalates to critical
     * @param int                                                                                          $retryMaxAttempts total attempts of RetryPolicy, first one included
     * @param int                                                                                          $retryBaseDelay seconds before the second attempt after a 429 without Retry-After
     * @param int                                                                                          $retryServerErrorDelay seconds before the second attempt after 5xx / network failures
     * @param string|null                                                                                  $previousKey the key before a rotation: still served/accepted by the key file, never submitted
     * @param array<mixed, mixed>                                                                          $logLevels log event ({@see LOG_EVENTS}) => PSR-3 level, overriding the shipped level
     * @param int                                                                                          $resolverMaxViaDepth how many `via:` hops a rule may follow
     * @param int                                                                                          $resolverMaxViaFanout how many related objects one `via:` hop may yield
     * @param string                                                                                       $debounceKeyPrefix cache key prefix of the shared debounce store (one per application sharing a pool)
     * @param int                                                                                          $collectorMaxUrls flush the collector as soon as it holds this many URLs (0 = only at request end)
     * @param bool                                                                                         $collectorDetectLeaks warn at shutdown about collected URLs that were never flushed
     * @param int                                                                                          $logBody bytes of a response body kept in a failure log line
     * @param array<mixed, mixed>                                                                          $engineAliases short names for custom endpoints: `{corp: 'https://index.corp.example/indexnow'}`
     * @param array<mixed, mixed>                                                                          $localeHosts locale => host for multi-domain locales: `{en: 'www.example.com', de: 'example.de'}`
     * @param int                                                                                          $keyFileMaxAge `Cache-Control: max-age` of the key file response (`key_file.cache_max_age`)
     * @param string|null                                                                                  $debounceStore `debounce.store`: null = the adapter's default, `memory`, `none`, or an id the adapter resolves to its cache
     * @param string|null                                                                                  $httpClient `http.client`: id or class of a PSR-18 client the adapter resolves; null = discovery
     * @param bool                                                                                         $dryRunExplicit whether $dryRun was chosen by the configuration; see {@see $dryRunExplicit}
     * @param bool                                                                                         $normalizerStripTrackingParams `normalizer.strip_tracking_params`: drop utm_*, gclid, fbclid, … before dedup, debounce and submission
     * @param array<mixed, mixed>                                                                          $normalizerTrackingParams `normalizer.tracking_params`: more names (`name` or `prefix*`) to drop
     * @param string                                                                                       $normalizerTrailingSlash `normalizer.trailing_slash`: keep | add | strip
     * @param bool                                                                                         $normalizerSortQuery `normalizer.sort_query`: order query parameters by name
     *
     * @throws ConfigurationException
     */
    public function __construct(
        public bool $enabled = true,
        public ?string $key = null,
        array $hosts = [],
        public ?string $keyLocation = null,
        public ?string $baseUrl = null,
        array $engines = [Engine::Api->value],
        public string $dispatch = 'sync',
        public int $batchMaxUrls = self::DEFAULT_BATCH_MAX_URLS,
        public int $debouncePerUrl = self::DEFAULT_DEBOUNCE_PER_URL,
        public int $throttleMaxRequestsPerMinute = self::DEFAULT_THROTTLE_PER_MINUTE,
        public float $httpTimeout = self::DEFAULT_HTTP_TIMEOUT,
        public ?string $userAgent = null,
        public bool $serveKeyFile = true,
        public bool $dryRun = false,
        public bool $strictHosts = false,
        public ?string $environment = null,
        array $productionEnvironments = self::PRODUCTION_ENVIRONMENTS,
        public int $maxUrlLength = self::DEFAULT_MAX_URL_LENGTH,
        public int $logUrls = self::DEFAULT_LOG_URLS,
        public int $forbiddenEscalation = self::DEFAULT_FORBIDDEN_ESCALATION,
        public int $retryMaxAttempts = self::DEFAULT_RETRY_MAX_ATTEMPTS,
        public int $retryBaseDelay = self::DEFAULT_RETRY_BASE_DELAY,
        public float $retryMultiplier = self::DEFAULT_RETRY_MULTIPLIER,
        public int $retryMaxDelay = self::DEFAULT_RETRY_MAX_DELAY,
        public int $retryServerErrorDelay = self::DEFAULT_RETRY_SERVER_ERROR_DELAY,
        public ?string $previousKey = null,
        array $logLevels = [],
        public int $resolverMaxViaDepth = self::DEFAULT_RESOLVER_MAX_VIA_DEPTH,
        public int $resolverMaxViaFanout = self::DEFAULT_RESOLVER_MAX_VIA_FANOUT,
        public string $debounceKeyPrefix = self::DEFAULT_DEBOUNCE_KEY_PREFIX,
        public int $collectorMaxUrls = 0,
        public bool $collectorDetectLeaks = true,
        public int $logBody = self::DEFAULT_LOG_BODY,
        array $engineAliases = [],
        array $localeHosts = [],
        public int $keyFileMaxAge = KeyFileResponder::DEFAULT_MAX_AGE,
        public ?string $debounceStore = null,
        public ?string $httpClient = null,
        bool $dryRunExplicit = true,
        public bool $normalizerStripTrackingParams = true,
        array $normalizerTrackingParams = [],
        public string $normalizerTrailingSlash = self::DEFAULT_TRAILING_SLASH,
        public bool $normalizerSortQuery = false,
    ) {
        $this->dryRunExplicit = $dryRunExplicit;
        if (!\in_array($normalizerTrailingSlash, self::TRAILING_SLASH_MODES, true)) {
            throw new ConfigurationException(\sprintf('"normalizer.trailing_slash" must be one of %s, got "%s".', implode(', ', self::TRAILING_SLASH_MODES), $normalizerTrailingSlash));
        }
        $this->normalizerTrackingParams = ConfigNormalizer::trackingParams($normalizerTrackingParams);
        if ($logBody < 0) {
            throw new ConfigurationException(\sprintf('"logging.max_body" must be >= 0, got %d.', $logBody));
        }
        if ($keyFileMaxAge < 0) {
            throw new ConfigurationException(\sprintf('"key_file.cache_max_age" must be >= 0 seconds, got %d.', $keyFileMaxAge));
        }
        if ($debounceStore === '') {
            throw new ConfigurationException('"debounce.store" must be "memory", "none" or the id of a cache, not an empty string.');
        }
        if ($httpClient === '') {
            throw new ConfigurationException('"http.client" must be the id or class of a PSR-18 client, not an empty string.');
        }
        $this->engineAliases = ConfigNormalizer::engineAliases($engineAliases);
        $this->localeHosts = ConfigNormalizer::localeHosts($localeHosts);
        if ($previousKey !== null) {
            KeyValidator::assertValid($previousKey);
        }
        $this->logLevels = ConfigNormalizer::logLevels($logLevels, self::LOG_EVENTS, self::LOG_LEVELS);
        if ($resolverMaxViaDepth < 0) {
            throw new ConfigurationException(\sprintf('"resolver.max_via_depth" must be >= 0 (0 = rules may not follow `via:` at all), got %d.', $resolverMaxViaDepth));
        }
        if ($resolverMaxViaFanout < 1) {
            throw new ConfigurationException(\sprintf('"resolver.max_via_fanout" must be >= 1 (related objects one `via:` hop may yield), got %d.', $resolverMaxViaFanout));
        }
        if ($collectorMaxUrls < 0) {
            throw new ConfigurationException(\sprintf('"collector.max_urls" must be >= 0 (0 = no early flush), got %d.', $collectorMaxUrls));
        }
        if ($debounceKeyPrefix === '' || preg_match('/[{}()\/\\@:\s]/', $debounceKeyPrefix) === 1) {
            throw new ConfigurationException(\sprintf('"debounce.key_prefix" must be a non-empty string without the characters PSR-6 reserves in cache keys ({}()/\\@:) or whitespace, got "%s". Letters, digits, "_", "-" and "." are safe.', $debounceKeyPrefix));
        }
        $this->productionEnvironments = ConfigNormalizer::productionEnvironments($productionEnvironments);
        if ($maxUrlLength < 64) {
            throw new ConfigurationException(\sprintf('"max_url_length" must be >= 64 bytes, got %d.', $maxUrlLength));
        }
        if ($logUrls < 0) {
            throw new ConfigurationException(\sprintf('"logging.max_urls" must be >= 0 (0 = list no URL), got %d.', $logUrls));
        }
        if ($forbiddenEscalation < 1) {
            throw new ConfigurationException(\sprintf('"logging.forbidden_escalation" must be >= 1, got %d.', $forbiddenEscalation));
        }
        if ($retryMaxAttempts < 1) {
            throw new ConfigurationException(\sprintf('"retry.max_attempts" must be >= 1 (the first attempt counts; 1 = never retry), got %d.', $retryMaxAttempts));
        }
        if ($retryBaseDelay < 0) {
            throw new ConfigurationException(\sprintf('"retry.base_delay" must be >= 0 seconds (the wait before the second attempt after a 429), got %d.', $retryBaseDelay));
        }
        if ($retryMultiplier < 1.0) {
            throw new ConfigurationException(\sprintf('"retry.multiplier" must be >= 1.0 (each further wait is the previous one times this), got %s.', $retryMultiplier));
        }
        if ($retryMaxDelay < 0) {
            throw new ConfigurationException(\sprintf('"retry.max_delay" must be >= 0 seconds (the ceiling of the growing wait), got %d.', $retryMaxDelay));
        }
        if ($retryServerErrorDelay < 0) {
            throw new ConfigurationException(\sprintf('"retry.server_error_delay" must be >= 0 seconds (the wait after a 5xx or a network failure), got %d.', $retryServerErrorDelay));
        }
        if ($enabled && !$dryRun && $key === null && $hosts === []) {
            throw new ConfigurationException('IndexNow is enabled but no "key" (or "hosts" map) is configured. Set INDEXNOW_KEY, or enable dry_run.');
        }
        if ($key !== null) {
            KeyValidator::assertValid($key);
        }
        [$this->hosts, $this->keyLocations, $this->hostBaseUrls, $this->hostEngines, $this->previousKeys] = ConfigNormalizer::hosts($hosts);
        if ($baseUrl !== null && !ConfigNormalizer::isAbsoluteHttpUrl($baseUrl)) {
            throw new ConfigurationException(\sprintf('"base_url" must be an absolute http(s) URL, got "%s".', $baseUrl));
        }
        if ($keyLocation !== null && !ConfigNormalizer::isKeyFileUrl($keyLocation)) {
            throw new ConfigurationException(\sprintf('"key_location" must be an absolute http(s) URL to the key file, got "%s".', $keyLocation));
        }
        if ($keyLocation !== null && $baseUrl !== null && ConfigNormalizer::hostOf($keyLocation) !== ConfigNormalizer::hostOf($baseUrl)) {
            throw new ConfigurationException(\sprintf('"key_location" (%s) must be on the host of "base_url" (%s): engines only accept a key file served from the submitted host.', ConfigNormalizer::hostOf($keyLocation), ConfigNormalizer::hostOf($baseUrl)));
        }
        if ($batchMaxUrls < 1 || $batchMaxUrls > self::MAX_BATCH_URLS) {
            throw new ConfigurationException(\sprintf('"batch.max_urls" must be between 1 and %d, got %d.', self::MAX_BATCH_URLS, $batchMaxUrls));
        }
        if ($debouncePerUrl < 0) {
            throw new ConfigurationException(\sprintf('"debounce.per_url" must be >= 0 seconds, got %d.', $debouncePerUrl));
        }
        if ($throttleMaxRequestsPerMinute < 0) {
            throw new ConfigurationException(\sprintf('"throttle.max_requests_per_minute" must be >= 0 (0 = unlimited), got %d.', $throttleMaxRequestsPerMinute));
        }
        if ($httpTimeout <= 0) {
            throw new ConfigurationException(\sprintf('"http.timeout" must be > 0 seconds, got %s.', $httpTimeout));
        }
        if ($engines === []) {
            throw new ConfigurationException('"engines" must contain at least one engine.');
        }
        if (preg_match('/^[a-z0-9_-]+$/i', $dispatch) !== 1) {
            throw new ConfigurationException(\sprintf('"dispatch" must be a short identifier such as sync, queue or none, got "%s".', $dispatch));
        }
        if ($userAgent !== null && preg_match('/[\r\n]/', $userAgent) === 1) {
            throw new ConfigurationException('"http.user_agent" must not contain line breaks.');
        }
        if ($strictHosts && $baseUrl === null && $this->hosts === []) {
            throw new ConfigurationException('"strict_hosts" needs at least one known host: set "base_url" or a "hosts" map.');
        }
        $this->engines = array_values($engines);
        $this->endpoints = array_values(array_unique(array_map($this->resolveEngine(...), $engines)));
        $hostEndpoints = [];
        foreach ($this->hostEngines as $host => $hostEngineList) {
            $hostEndpoints[$host] = array_values(array_unique(array_map($this->resolveEngine(...), $hostEngineList)));
        }
        $this->hostEndpoints = $hostEndpoints;
    }

    /**
     * Engine name, alias ({@see $engineAliases}) or endpoint URL to its endpoint.
     *
     * @throws ConfigurationException
     */
    public function resolveEngine(string $engine): string
    {
        return Engine::resolveEndpoint($this->engineAliases[strtolower(trim($engine))] ?? $engine);
    }

    /**
     * Host of a locale ({@see $localeHosts}), or null when the locale has no host of its own.
     */
    public function hostForLocale(?string $locale): ?string
    {
        return $locale === null ? null : ($this->localeHosts[strtolower($locale)] ?? null);
    }

    /**
     * Endpoints URLs of $host go to: `hosts.<host>.engines` when set, else `engines`.
     *
     * @return list<string>
     */
    public function endpointsFor(string $host): array
    {
        return $this->hostEndpoints[strtolower($host)] ?? $this->endpoints;
    }

    /**
     * PSR-3 level for a log event ({@see LOG_EVENTS}): the configured override, else the shipped default.
     */
    public function logLevel(string $event): string
    {
        return $this->logLevels[$event] ?? self::LOG_EVENTS[$event] ?? 'info';
    }

    /**
     * Build from the canonical nested array shape used by framework configs.
     *
     * Outside production ("environment" key not in {@see PRODUCTION_ENVIRONMENTS}) a missing key switches
     * dry_run on instead of failing, so dev setups never hit the real API.
     *
     * @param array<string, mixed> $data
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $data): self
    {
        return ConfigParser::fromArray($data);
    }

    /**
     * Whether the raw configuration asks the application to serve the key file: the explicit `serve_key_file`
     * (the pre-0.4 name) wins over `key_file.enabled`, then the default is true. The one place with that rule,
     * for adapters that read the raw array before a `Config` exists (a route registered at boot, a check over the
     * component options); `fromArray()` uses it too, with the same string parsing.
     *
     * @param array<string, mixed> $data the raw array `fromArray()` takes
     *
     * @throws ConfigurationException when either value is not a boolean
     */
    public static function serveKeyFileFrom(array $data): bool
    {
        return ConfigParser::serveKeyFileFrom($data);
    }

    /**
     * Build from environment variables.
     *
     * Recognised (with the default prefix): INDEXNOW_ENABLED, INDEXNOW_KEY, INDEXNOW_HOSTS ("host=key,host2=key2"),
     * INDEXNOW_KEY_LOCATION, INDEXNOW_BASE_URL, INDEXNOW_ENGINES ("api" or "yandex,bing"), INDEXNOW_DISPATCH,
     * INDEXNOW_BATCH_MAX_URLS, INDEXNOW_DEBOUNCE_PER_URL, INDEXNOW_THROTTLE_PER_MINUTE, INDEXNOW_HTTP_TIMEOUT,
     * INDEXNOW_USER_AGENT, INDEXNOW_SERVE_KEY_FILE, INDEXNOW_DRY_RUN, INDEXNOW_STRICT_HOSTS, INDEXNOW_MAX_URL_LENGTH,
     * INDEXNOW_PRODUCTION_ENVIRONMENTS ("prod,live"), INDEXNOW_LOG_URLS, INDEXNOW_FORBIDDEN_ESCALATION,
     * INDEXNOW_RETRY_MAX_ATTEMPTS, INDEXNOW_RETRY_BASE_DELAY, INDEXNOW_RETRY_MULTIPLIER, INDEXNOW_RETRY_MAX_DELAY,
     * INDEXNOW_RETRY_SERVER_ERROR_DELAY, INDEXNOW_KEY_FILE_ENABLED, INDEXNOW_KEY_FILE_CACHE_MAX_AGE,
     * INDEXNOW_DEBOUNCE_STORE, INDEXNOW_HTTP_CLIENT, INDEXNOW_PREVIOUS_KEY (the key before a rotation), plus
     * INDEXNOW_ENV / APP_ENV for the non-production dry-run safety net.
     *
     * @param array<string, mixed>|null $env defaults to getenv() + $_SERVER + $_ENV
     *
     * @throws ConfigurationException
     */
    public static function fromEnv(?array $env = null, string $prefix = 'INDEXNOW_'): self
    {
        return ConfigParser::fromEnv($env ?? self::processEnvironment(), $prefix);
    }

    /**
     * The same variables as {@see fromEnv()} reads, as the nested array {@see fromArray()} takes — **only the variables
     * that are set**: an unset or empty variable leaves no key, values stay strings (`fromArray()` coerces them). This
     * is what an application without a framework merges over its configuration file, environment on top
     * (`Config::fromArray(array_replace_recursive($file, Config::arrayFromEnv()))`); `toArray()` cannot serve there,
     * because it carries every default. `Config::fromArray(Config::arrayFromEnv($env))` equals `Config::fromEnv($env)`.
     *
     * @param array<string, mixed>|null $env defaults to getenv() + $_SERVER + $_ENV
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationException on an `INDEXNOW_HOSTS` entry without `=`
     */
    public static function arrayFromEnv(?array $env = null, string $prefix = 'INDEXNOW_'): array
    {
        return ConfigParser::envArray($env ?? self::processEnvironment(), $prefix);
    }

    /**
     * @return array<string, mixed>
     */
    private static function processEnvironment(): array
    {
        return array_merge(getenv(), $_SERVER, $_ENV);
    }

    /**
     * Copy with some values replaced, by constructor parameter name: `$config->with(dryRun: true, engines: ['yandex'])`.
     * Changing `dryRun` makes the copy explicit ({@see $dryRunExplicit}); other changes keep the flag as it is.
     *
     * @throws ConfigurationException
     */
    public function with(mixed ...$changes): self
    {
        $current = [];
        foreach ((new ReflectionMethod($this, '__construct'))->getParameters() as $parameter) {
            $name = $parameter->getName();
            $current[$name] = match ($name) {
                'hosts' => $this->hostsForConstructor(),
                'dryRunExplicit' => $this->dryRunExplicit || \array_key_exists('dryRun', $changes),
                default => get_object_vars($this)[$name],
            };
        }
        foreach ($changes as $name => $value) {
            if (!\is_string($name) || !\array_key_exists($name, $current)) {
                throw new ConfigurationException(\sprintf('Unknown Config option "%s". Known options: %s.', (string) $name, implode(', ', array_keys($current))));
            }
        }

        /** @phpstan-ignore-next-line argument.type */
        return new self(...array_replace($current, $changes));
    }

    public function withDryRun(bool $dryRun): self
    {
        return $this->with(dryRun: $dryRun);
    }

    /**
     * The effective configuration in the nested shape {@see fromArray()} takes, every option of {@see OPTIONS} present
     * with its resolved value (`key_file.enabled` carries `serve_key_file`; `hosts` entries are `key` strings or
     * `{key, key_location, base_url, engines, previous_key}`). Keys are **not** masked: the `config` command does that.
     * `Config::fromArray($config->toArray())` is an equal configuration.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'key' => $this->key,
            'previous_key' => $this->previousKey,
            'hosts' => $this->hostsForConstructor(),
            'key_location' => $this->keyLocation,
            'base_url' => $this->baseUrl,
            'strict_hosts' => $this->strictHosts,
            'engines' => $this->engines,
            'engine_aliases' => $this->engineAliases,
            'locale_hosts' => $this->localeHosts,
            'dispatch' => $this->dispatch,
            'dry_run' => $this->dryRun,
            'environment' => $this->environment,
            'production_environments' => $this->productionEnvironments,
            'max_url_length' => $this->maxUrlLength,
            'key_file' => ['enabled' => $this->serveKeyFile, 'cache_max_age' => $this->keyFileMaxAge],
            'batch' => ['max_urls' => $this->batchMaxUrls],
            'debounce' => ['per_url' => $this->debouncePerUrl, 'key_prefix' => $this->debounceKeyPrefix, 'store' => $this->debounceStore],
            'throttle' => ['max_requests_per_minute' => $this->throttleMaxRequestsPerMinute],
            'http' => ['timeout' => $this->httpTimeout, 'user_agent' => $this->userAgent, 'client' => $this->httpClient],
            'logging' => ['max_urls' => $this->logUrls, 'forbidden_escalation' => $this->forbiddenEscalation, 'levels' => $this->logLevels, 'max_body' => $this->logBody],
            'retry' => ['max_attempts' => $this->retryMaxAttempts, 'base_delay' => $this->retryBaseDelay, 'multiplier' => $this->retryMultiplier, 'max_delay' => $this->retryMaxDelay, 'server_error_delay' => $this->retryServerErrorDelay],
            'resolver' => ['max_via_depth' => $this->resolverMaxViaDepth, 'max_via_fanout' => $this->resolverMaxViaFanout],
            'collector' => ['max_urls' => $this->collectorMaxUrls, 'detect_leaks' => $this->collectorDetectLeaks],
            'normalizer' => ['strip_tracking_params' => $this->normalizerStripTrackingParams, 'tracking_params' => $this->normalizerTrackingParams, 'trailing_slash' => $this->normalizerTrailingSlash, 'sort_query' => $this->normalizerSortQuery],
        ];
    }

    public function userAgent(): string
    {
        return $this->userAgent ?? 'indexnowkit-php/' . Version::get() . ' (+https://github.com/indexnowkit/php)';
    }

    /**
     * Whether `environment` is one of `production_environments` (default {@see PRODUCTION_ENVIRONMENTS}); false when unknown.
     */
    public function isProduction(): bool
    {
        return $this->environment !== null && \in_array(strtolower($this->environment), $this->productionEnvironments, true);
    }

    /**
     * Retry policy for queue handlers and {@see \IndexNowKit\Retry\RetryingSubmitter}, from the `retry.*` options.
     */
    public function retryPolicy(): Retry\RetryPolicy
    {
        return new Retry\RetryPolicy($this->retryMaxAttempts, $this->retryBaseDelay, $this->retryMultiplier, $this->retryMaxDelay, $this->retryServerErrorDelay);
    }

    /**
     * Response headers of the key file: `key_file.cache_max_age`, and `Vary: Host` when the body depends on the host:
     * a `hosts` map, or `strict_hosts` (the default key is served for the base host only, other hosts get a 404, and
     * a shared cache without `Vary` would keep whichever answer came first). Every adapter used to compute this itself.
     *
     * @return array<string, string>
     */
    public function keyFileHeaders(): array
    {
        return KeyFileResponder::headers($this->keyFileMaxAge, $this->hosts !== [] || $this->strictHosts);
    }

    /**
     * The first $logUrls entries of a URL list, for log context (the count goes in the message).
     *
     * @param list<string> $urls
     *
     * @return list<string>
     */
    public function logSample(array $urls): array
    {
        return \array_slice($urls, 0, $this->logUrls);
    }

    /**
     * Base URL to generate absolute URLs for a host: the per-host override, else base_url when it is that host, else null.
     */
    public function baseUrlFor(string $host): ?string
    {
        $host = strtolower($host);
        if (isset($this->hostBaseUrls[$host])) {
            return $this->hostBaseUrls[$host];
        }

        return $this->baseHost() === $host ? $this->baseUrl : null;
    }

    /**
     * Keys of $data that fromArray() does not understand, as dotted paths. Adapters strip their own keys via
     * $allowed and warn (or fail) on the remainder, so `debounce.per_urls` does not pass silently.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $allowed extra dotted keys owned by the adapter (a prefix like "messenger" allows the whole block)
     *
     * @return list<string>
     */
    public static function unknownOptions(array $data, array $allowed = []): array
    {
        $known = [...self::OPTIONS, ...$allowed];
        $unknown = [];
        foreach ($data as $name => $value) {
            $name = (string) $name;
            if ($name === 'hosts' || \in_array($name, $known, true)) {
                continue;
            }
            if (\is_array($value) && !array_is_list($value)) {
                self::unknownIn($value, $name, $known, $unknown);
                continue;
            }
            $unknown[] = $name;
        }

        return $unknown;
    }

    /**
     * The keys of a block that no known dotted key names: a nested block (`history.pdo` under `history.pdo.dsn`) is
     * walked down as long as a known key starts with its path, so a block is reported by its unknown leaves, never as
     * a whole because it has children.
     *
     * @param array<array-key, mixed> $block
     * @param list<string>            $known
     * @param list<string>            $unknown
     */
    private static function unknownIn(array $block, string $prefix, array $known, array &$unknown): void
    {
        foreach ($block as $sub => $value) {
            $path = $prefix . '.' . (string) $sub;
            if (\in_array($path, $known, true)) {
                continue;
            }
            $nested = \is_array($value) && !array_is_list($value) && array_filter($known, static fn(string $option): bool => str_starts_with($option, $path . '.')) !== [];
            if ($nested) {
                /** @var array<array-key, mixed> $value */
                self::unknownIn($value, $path, $known, $unknown);
                continue;
            }
            $unknown[] = $path;
        }
    }

    /**
     * Host of base_url, lower-cased and in punycode (the form `UrlNormalizer` gives every submitted URL, so that an
     * IDN base_url matches its own URLs in `strict_hosts`, the redirect allow-list of verify and `check`), or null.
     */
    public function baseHost(): ?string
    {
        if ($this->baseUrl === null) {
            return null;
        }
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return null;
        }

        return $host[0] === '[' ? strtolower($host) : strtolower(Punycode::encodeHost($host));
    }

    /**
     * @return array<string, string|array{key: string, key_location?: string|null, base_url?: string|null}>
     */
    private function hostsForConstructor(): array
    {
        $hosts = [];
        foreach ($this->hosts as $host => $key) {
            $entry = ['key' => $key];
            if (isset($this->keyLocations[$host])) {
                $entry['key_location'] = $this->keyLocations[$host];
            }
            if (isset($this->hostBaseUrls[$host])) {
                $entry['base_url'] = $this->hostBaseUrls[$host];
            }
            if (isset($this->hostEngines[$host])) {
                $entry['engines'] = $this->hostEngines[$host];
            }
            if (isset($this->previousKeys[$host])) {
                $entry['previous_key'] = $this->previousKeys[$host];
            }
            $hosts[$host] = \count($entry) === 1 ? $key : $entry;
        }

        return $hosts;
    }
}
