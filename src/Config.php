<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Key\KeyValidator;

/**
 * Immutable configuration shared by every indexnowkit adapter. Keys mirror docs/spec/02.
 *
 * Built with {@see fromArray()} (framework config), {@see fromEnv()} (INDEXNOW_* variables) or the
 * constructor; derived copies with {@see with()}.
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

    /** Every key fromArray() understands, dotted-path form. Adapters validate their own config against it with unknownOptions(). */
    public const OPTIONS = [
        'enabled', 'key', 'hosts', 'key_location', 'base_url', 'engines', 'dispatch', 'serve_key_file', 'dry_run',
        'strict_hosts', 'environment', 'production_environments', 'max_url_length', 'previous_key',
        'batch.max_urls', 'debounce.per_url', 'debounce.key_prefix', 'throttle.max_requests_per_minute',
        'http.timeout', 'http.user_agent',
        'logging.max_urls', 'logging.forbidden_escalation', 'logging.levels', 'logging.max_body', 'engine_aliases', 'locale_hosts',
        'retry.max_attempts', 'retry.base_delay', 'retry.multiplier', 'retry.max_delay', 'retry.server_error_delay',
        'resolver.max_via_depth', 'resolver.max_via_fanout', 'collector.max_urls', 'collector.detect_leaks',
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
    ) {
        if ($logBody < 0) {
            throw new ConfigurationException(\sprintf('"logging.max_body" must be >= 0, got %d.', $logBody));
        }
        $this->engineAliases = self::normalizeEngineAliases($engineAliases);
        $this->localeHosts = self::normalizeLocaleHosts($localeHosts);
        if ($previousKey !== null) {
            KeyValidator::assertValid($previousKey);
        }
        $this->logLevels = self::normalizeLogLevels($logLevels);
        if ($resolverMaxViaDepth < 0 || $resolverMaxViaFanout < 1) {
            throw new ConfigurationException('"resolver": max_via_depth >= 0, max_via_fanout >= 1.');
        }
        if ($collectorMaxUrls < 0) {
            throw new ConfigurationException(\sprintf('"collector.max_urls" must be >= 0 (0 = no early flush), got %d.', $collectorMaxUrls));
        }
        if ($debounceKeyPrefix === '' || preg_match('/[{}()\/\\@:\s]/', $debounceKeyPrefix) === 1) {
            throw new ConfigurationException(\sprintf('"debounce.key_prefix" must be a non-empty PSR-6 safe string, got "%s".', $debounceKeyPrefix));
        }
        $this->productionEnvironments = array_values(array_unique(array_map(static fn(string $e): string => strtolower(trim($e)), array_filter($productionEnvironments, static fn(mixed $e): bool => \is_string($e) && trim($e) !== ''))));
        if ($this->productionEnvironments === []) {
            throw new ConfigurationException('"production_environments" must name at least one environment.');
        }
        if ($maxUrlLength < 64) {
            throw new ConfigurationException(\sprintf('"max_url_length" must be >= 64 bytes, got %d.', $maxUrlLength));
        }
        if ($logUrls < 0) {
            throw new ConfigurationException(\sprintf('"logging.max_urls" must be >= 0 (0 = list no URL), got %d.', $logUrls));
        }
        if ($forbiddenEscalation < 1) {
            throw new ConfigurationException(\sprintf('"logging.forbidden_escalation" must be >= 1, got %d.', $forbiddenEscalation));
        }
        if ($retryMaxAttempts < 1 || $retryBaseDelay < 0 || $retryMultiplier < 1.0 || $retryMaxDelay < 0 || $retryServerErrorDelay < 0) {
            throw new ConfigurationException('"retry": max_attempts >= 1, multiplier >= 1.0, delays >= 0 seconds.');
        }
        if ($enabled && !$dryRun && $key === null && $hosts === []) {
            throw new ConfigurationException('IndexNow is enabled but no "key" (or "hosts" map) is configured. Set INDEXNOW_KEY, or enable dry_run.');
        }
        if ($key !== null) {
            KeyValidator::assertValid($key);
        }
        [$this->hosts, $this->keyLocations, $this->hostBaseUrls, $this->hostEngines, $this->previousKeys] = self::normalizeHosts($hosts);
        if ($baseUrl !== null && !self::isAbsoluteHttpUrl($baseUrl)) {
            throw new ConfigurationException(\sprintf('"base_url" must be an absolute http(s) URL, got "%s".', $baseUrl));
        }
        if ($keyLocation !== null && !self::isKeyFileUrl($keyLocation)) {
            throw new ConfigurationException(\sprintf('"key_location" must be an absolute http(s) URL to the key file, got "%s".', $keyLocation));
        }
        if ($keyLocation !== null && $baseUrl !== null && self::hostOf($keyLocation) !== self::hostOf($baseUrl)) {
            throw new ConfigurationException(\sprintf('"key_location" (%s) must be on the host of "base_url" (%s): engines only accept a key file served from the submitted host.', self::hostOf($keyLocation), self::hostOf($baseUrl)));
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
     * @param array<mixed, mixed> $aliases
     *
     * @return array<string, string>
     *
     * @throws ConfigurationException
     */
    private static function normalizeEngineAliases(array $aliases): array
    {
        $out = [];
        foreach ($aliases as $name => $endpoint) {
            if (!\is_string($name) || preg_match('/^[a-z][a-z0-9_-]*$/i', $name) !== 1 || Engine::tryFrom(strtolower($name)) !== null) {
                throw new ConfigurationException(\sprintf('"engine_aliases" names must be identifiers that are not built-in engines, got "%s".', (string) $name));
            }
            if (!\is_string($endpoint) || !self::isAbsoluteHttpUrl($endpoint)) {
                throw new ConfigurationException(\sprintf('"engine_aliases.%s" must be an endpoint URL.', $name));
            }
            $out[strtolower($name)] = Engine::resolveEndpoint($endpoint);
        }

        return $out;
    }

    /**
     * @param array<mixed, mixed> $hosts
     *
     * @return array<string, string>
     *
     * @throws ConfigurationException
     */
    private static function normalizeLocaleHosts(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $locale => $host) {
            if (!\is_string($locale) || $locale === '' || !\is_string($host) || preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)$/i', $host) !== 1) {
                throw new ConfigurationException(\sprintf('"locale_hosts" must map locales to bare host names, got "%s" => %s.', (string) $locale, \is_scalar($host) ? '"' . (string) $host . '"' : get_debug_type($host)));
            }
            $out[strtolower($locale)] = strtolower($host);
        }

        return $out;
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
     * @param array<mixed, mixed> $levels
     *
     * @return array<string, string>
     *
     * @throws ConfigurationException
     */
    private static function normalizeLogLevels(array $levels): array
    {
        $out = [];
        foreach ($levels as $event => $level) {
            if (!\is_string($event) || !isset(self::LOG_EVENTS[$event])) {
                throw new ConfigurationException(\sprintf('"logging.levels" has an unknown event "%s"; known: %s.', (string) $event, implode(', ', array_keys(self::LOG_EVENTS))));
            }
            if (!\is_string($level) || !\in_array(strtolower($level), self::LOG_LEVELS, true)) {
                throw new ConfigurationException(\sprintf('"logging.levels.%s" must be a PSR-3 level (%s), got %s.', $event, implode(', ', self::LOG_LEVELS), \is_scalar($level) ? '"' . (string) $level . '"' : get_debug_type($level)));
            }
            $out[$event] = strtolower($level);
        }

        return $out;
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
        $batch = self::sub($data, 'batch');
        $debounce = self::sub($data, 'debounce');
        $throttle = self::sub($data, 'throttle');
        $http = self::sub($data, 'http');
        $logging = self::sub($data, 'logging');
        $retry = self::sub($data, 'retry');
        $resolver = self::sub($data, 'resolver');
        $collector = self::sub($data, 'collector');
        /** @var array<mixed, mixed> $logLevels */
        $logLevels = \is_array($logging['levels'] ?? null) ? $logging['levels'] : [];
        /** @var array<mixed, mixed> $engineAliases */
        $engineAliases = \is_array($data['engine_aliases'] ?? null) ? $data['engine_aliases'] : [];
        /** @var array<mixed, mixed> $localeHosts */
        $localeHosts = \is_array($data['locale_hosts'] ?? null) ? $data['locale_hosts'] : [];
        $productionEnvironments = self::list($data['production_environments'] ?? null) ?? self::PRODUCTION_ENVIRONMENTS;

        /** @var array<string, string|array{key: string, key_location?: string|null, base_url?: string|null, engines?: list<string>|null, previous_key?: string|null}> $hosts */
        $hosts = \is_array($data['hosts'] ?? null) ? $data['hosts'] : [];
        /** @var list<string> $engines */
        $engines = \is_array($data['engines'] ?? null) ? array_values($data['engines']) : [Engine::Api->value];
        $key = self::str($data['key'] ?? null);
        $dryRun = (bool) ($data['dry_run'] ?? false);
        $environment = self::str($data['environment'] ?? null);
        if ($key === null && $hosts === [] && $environment !== null && !\in_array(strtolower($environment), array_map('strtolower', $productionEnvironments), true)) {
            $dryRun = true;
        }

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            key: $key,
            hosts: $hosts,
            keyLocation: self::str($data['key_location'] ?? null),
            baseUrl: self::str($data['base_url'] ?? null),
            engines: $engines,
            dispatch: self::str($data['dispatch'] ?? null) ?? 'sync',
            batchMaxUrls: self::int($batch['max_urls'] ?? null, self::DEFAULT_BATCH_MAX_URLS, 'batch.max_urls'),
            debouncePerUrl: self::int($debounce['per_url'] ?? null, self::DEFAULT_DEBOUNCE_PER_URL, 'debounce.per_url'),
            throttleMaxRequestsPerMinute: self::int($throttle['max_requests_per_minute'] ?? null, self::DEFAULT_THROTTLE_PER_MINUTE, 'throttle.max_requests_per_minute'),
            httpTimeout: self::float($http['timeout'] ?? null, self::DEFAULT_HTTP_TIMEOUT, 'http.timeout'),
            userAgent: self::str($http['user_agent'] ?? null),
            serveKeyFile: (bool) ($data['serve_key_file'] ?? true),
            dryRun: $dryRun,
            strictHosts: (bool) ($data['strict_hosts'] ?? false),
            environment: $environment,
            productionEnvironments: $productionEnvironments,
            maxUrlLength: self::int($data['max_url_length'] ?? null, self::DEFAULT_MAX_URL_LENGTH, 'max_url_length'),
            logUrls: self::int($logging['max_urls'] ?? null, self::DEFAULT_LOG_URLS, 'logging.max_urls'),
            forbiddenEscalation: self::int($logging['forbidden_escalation'] ?? null, self::DEFAULT_FORBIDDEN_ESCALATION, 'logging.forbidden_escalation'),
            retryMaxAttempts: self::int($retry['max_attempts'] ?? null, self::DEFAULT_RETRY_MAX_ATTEMPTS, 'retry.max_attempts'),
            retryBaseDelay: self::int($retry['base_delay'] ?? null, self::DEFAULT_RETRY_BASE_DELAY, 'retry.base_delay'),
            retryMultiplier: self::float($retry['multiplier'] ?? null, self::DEFAULT_RETRY_MULTIPLIER, 'retry.multiplier'),
            retryMaxDelay: self::int($retry['max_delay'] ?? null, self::DEFAULT_RETRY_MAX_DELAY, 'retry.max_delay'),
            retryServerErrorDelay: self::int($retry['server_error_delay'] ?? null, self::DEFAULT_RETRY_SERVER_ERROR_DELAY, 'retry.server_error_delay'),
            previousKey: self::str($data['previous_key'] ?? null),
            logLevels: $logLevels,
            resolverMaxViaDepth: self::int($resolver['max_via_depth'] ?? null, self::DEFAULT_RESOLVER_MAX_VIA_DEPTH, 'resolver.max_via_depth'),
            resolverMaxViaFanout: self::int($resolver['max_via_fanout'] ?? null, self::DEFAULT_RESOLVER_MAX_VIA_FANOUT, 'resolver.max_via_fanout'),
            debounceKeyPrefix: self::str($debounce['key_prefix'] ?? null) ?? self::DEFAULT_DEBOUNCE_KEY_PREFIX,
            collectorMaxUrls: self::int($collector['max_urls'] ?? null, 0, 'collector.max_urls'),
            collectorDetectLeaks: (bool) ($collector['detect_leaks'] ?? true),
            logBody: self::int($logging['max_body'] ?? null, self::DEFAULT_LOG_BODY, 'logging.max_body'),
            engineAliases: $engineAliases,
            localeHosts: $localeHosts,
        );
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
     * INDEXNOW_RETRY_SERVER_ERROR_DELAY, plus INDEXNOW_ENV / APP_ENV for the non-production dry-run safety net.
     *
     * @param array<string, mixed>|null $env defaults to getenv() + $_SERVER + $_ENV
     *
     * @throws ConfigurationException
     */
    public static function fromEnv(?array $env = null, string $prefix = 'INDEXNOW_'): self
    {
        $env ??= array_merge(getenv(), $_SERVER, $_ENV);
        $get = static function (string $name) use ($env, $prefix): ?string {
            $value = $env[$prefix . $name] ?? null;

            return \is_scalar($value) && (string) $value !== '' ? (string) $value : null;
        };
        $bool = static fn(?string $value): ?bool => $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $engines = $get('ENGINES');
        $appEnv = $env['APP_ENV'] ?? null;
        $csv = static fn(?string $value): ?array => $value === null ? null : array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $e) => $e !== ''));
        $logging = array_filter(['max_urls' => $get('LOG_URLS'), 'forbidden_escalation' => $get('FORBIDDEN_ESCALATION')], static fn($v) => $v !== null);
        $retry = array_filter(['max_attempts' => $get('RETRY_MAX_ATTEMPTS'), 'base_delay' => $get('RETRY_BASE_DELAY'), 'multiplier' => $get('RETRY_MULTIPLIER'), 'max_delay' => $get('RETRY_MAX_DELAY'), 'server_error_delay' => $get('RETRY_SERVER_ERROR_DELAY')], static fn($v) => $v !== null);

        return self::fromArray(array_filter([
            'enabled' => $bool($get('ENABLED')),
            'key' => $get('KEY'),
            'hosts' => self::parseHosts($get('HOSTS')),
            'key_location' => $get('KEY_LOCATION'),
            'base_url' => $get('BASE_URL'),
            'engines' => $csv($engines),
            'production_environments' => $csv($get('PRODUCTION_ENVIRONMENTS')),
            'max_url_length' => $get('MAX_URL_LENGTH'),
            'logging' => $logging === [] ? null : $logging,
            'retry' => $retry === [] ? null : $retry,
            'dispatch' => $get('DISPATCH'),
            'dry_run' => $bool($get('DRY_RUN')),
            'serve_key_file' => $bool($get('SERVE_KEY_FILE')),
            'strict_hosts' => $bool($get('STRICT_HOSTS')),
            'environment' => $get('ENV') ?? (\is_string($appEnv) ? $appEnv : null),
            'batch' => $get('BATCH_MAX_URLS') !== null ? ['max_urls' => $get('BATCH_MAX_URLS')] : null,
            'debounce' => $get('DEBOUNCE_PER_URL') !== null ? ['per_url' => $get('DEBOUNCE_PER_URL')] : null,
            'throttle' => $get('THROTTLE_PER_MINUTE') !== null ? ['max_requests_per_minute' => $get('THROTTLE_PER_MINUTE')] : null,
            'http' => ($http = array_filter(['timeout' => $get('HTTP_TIMEOUT'), 'user_agent' => $get('USER_AGENT')], static fn($v) => $v !== null)) === [] ? null : $http,
        ], static fn($v) => $v !== null));
    }

    /**
     * Copy with some values replaced, by constructor parameter name: `$config->with(dryRun: true, engines: ['yandex'])`.
     *
     * @throws ConfigurationException
     */
    public function with(mixed ...$changes): self
    {
        $current = [
            'enabled' => $this->enabled,
            'key' => $this->key,
            'hosts' => $this->hostsForConstructor(),
            'keyLocation' => $this->keyLocation,
            'baseUrl' => $this->baseUrl,
            'engines' => $this->engines,
            'dispatch' => $this->dispatch,
            'batchMaxUrls' => $this->batchMaxUrls,
            'debouncePerUrl' => $this->debouncePerUrl,
            'throttleMaxRequestsPerMinute' => $this->throttleMaxRequestsPerMinute,
            'httpTimeout' => $this->httpTimeout,
            'userAgent' => $this->userAgent,
            'serveKeyFile' => $this->serveKeyFile,
            'dryRun' => $this->dryRun,
            'strictHosts' => $this->strictHosts,
            'environment' => $this->environment,
            'productionEnvironments' => $this->productionEnvironments,
            'maxUrlLength' => $this->maxUrlLength,
            'logUrls' => $this->logUrls,
            'forbiddenEscalation' => $this->forbiddenEscalation,
            'retryMaxAttempts' => $this->retryMaxAttempts,
            'retryBaseDelay' => $this->retryBaseDelay,
            'retryMultiplier' => $this->retryMultiplier,
            'retryMaxDelay' => $this->retryMaxDelay,
            'retryServerErrorDelay' => $this->retryServerErrorDelay,
            'previousKey' => $this->previousKey,
            'logLevels' => $this->logLevels,
            'resolverMaxViaDepth' => $this->resolverMaxViaDepth,
            'resolverMaxViaFanout' => $this->resolverMaxViaFanout,
            'debounceKeyPrefix' => $this->debounceKeyPrefix,
            'collectorMaxUrls' => $this->collectorMaxUrls,
            'collectorDetectLeaks' => $this->collectorDetectLeaks,
            'logBody' => $this->logBody,
            'engineAliases' => $this->engineAliases,
            'localeHosts' => $this->localeHosts,
        ];
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
                foreach ($value as $sub => $subValue) {
                    $path = $name . '.' . (string) $sub;
                    if (!\in_array($path, $known, true)) {
                        $unknown[] = $path;
                    }
                }
                continue;
            }
            $unknown[] = $name;
        }

        return $unknown;
    }

    /**
     * Host of base_url, lower-cased, or null.
     */
    public function baseHost(): ?string
    {
        if ($this->baseUrl === null) {
            return null;
        }
        $host = parse_url($this->baseUrl, PHP_URL_HOST);

        return \is_string($host) ? strtolower($host) : null;
    }

    /**
     * @param array<string, string|array{key: string, key_location?: string|null, base_url?: string|null, engines?: list<string>|null, previous_key?: string|null}> $hosts
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>, 3: array<string, list<string>>, 4: array<string, string>}
     *
     * @throws ConfigurationException
     */
    private static function normalizeHosts(array $hosts): array
    {
        $keys = [];
        $locations = [];
        $baseUrls = [];
        $engines = [];
        $previous = [];
        foreach ($hosts as $host => $entry) {
            if (!\is_string($host) || $host === '' || preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)$/i', $host) !== 1) {
                throw new ConfigurationException(\sprintf('"hosts" must map bare host names (no scheme, port or path) to keys, got "%s".', (string) $host));
            }
            $host = strtolower($host);
            $key = \is_array($entry) ? ($entry['key'] ?? '') : $entry;
            if (!\is_string($key)) {
                throw new ConfigurationException(\sprintf('"hosts.%s" must be a key string or {key, key_location}.', $host));
            }
            KeyValidator::assertValid($key);
            $keys[$host] = $key;
            $location = \is_array($entry) ? ($entry['key_location'] ?? null) : null;
            if ($location !== null) {
                if (!\is_string($location) || !self::isKeyFileUrl($location)) {
                    throw new ConfigurationException(\sprintf('"hosts.%s.key_location" must be an absolute http(s) URL.', $host));
                }
                if (self::hostOf($location) !== $host) {
                    throw new ConfigurationException(\sprintf('"hosts.%s.key_location" must be on host %s, got %s.', $host, $host, self::hostOf($location)));
                }
                $locations[$host] = $location;
            }
            $baseUrl = \is_array($entry) ? ($entry['base_url'] ?? null) : null;
            if ($baseUrl !== null) {
                if (!\is_string($baseUrl) || !self::isAbsoluteHttpUrl($baseUrl)) {
                    throw new ConfigurationException(\sprintf('"hosts.%s.base_url" must be an absolute http(s) URL.', $host));
                }
                if (self::hostOf($baseUrl) !== $host) {
                    throw new ConfigurationException(\sprintf('"hosts.%s.base_url" must be on host %s, got %s.', $host, $host, self::hostOf($baseUrl)));
                }
                $baseUrls[$host] = $baseUrl;
            }
            $hostEngines = \is_array($entry) ? ($entry['engines'] ?? null) : null;
            if ($hostEngines !== null) {
                $list = self::list($hostEngines);
                if ($list === null || $list === []) {
                    throw new ConfigurationException(\sprintf('"hosts.%s.engines" must list at least one engine.', $host));
                }
                $engines[$host] = $list;
            }
            $previousKey = \is_array($entry) ? ($entry['previous_key'] ?? null) : null;
            if ($previousKey !== null) {
                if (!\is_string($previousKey)) {
                    throw new ConfigurationException(\sprintf('"hosts.%s.previous_key" must be a key string.', $host));
                }
                KeyValidator::assertValid($previousKey);
                $previous[$host] = $previousKey;
            }
        }

        return [$keys, $locations, $baseUrls, $engines, $previous];
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

    /**
     * @return array<string, string>|null
     */
    private static function parseHosts(?string $spec): ?array
    {
        if ($spec === null) {
            return null;
        }
        $hosts = [];
        foreach (explode(',', $spec) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            if (!str_contains($pair, '=')) {
                throw new ConfigurationException(\sprintf('INDEXNOW_HOSTS entries must look like "host=key", got "%s".', $pair));
            }
            [$host, $key] = explode('=', $pair, 2);
            $hosts[trim($host)] = trim($key);
        }

        return $hosts;
    }

    private static function isAbsoluteHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts) && isset($parts['scheme'], $parts['host']) && \in_array(strtolower($parts['scheme']), ['http', 'https'], true) && !isset($parts['user']) && !isset($parts['pass']);
    }

    private static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return \is_string($host) ? strtolower($host) : '';
    }

    private static function isKeyFileUrl(string $url): bool
    {
        return self::isAbsoluteHttpUrl($url) && \is_string(parse_url($url, PHP_URL_PATH)) && parse_url($url, PHP_URL_PATH) !== '/';
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function sub(array $data, string $name): array
    {
        $value = $data[$name] ?? null;

        return \is_array($value) ? $value : [];
    }

    /**
     * @return list<string>|null
     */
    private static function list(mixed $value): ?array
    {
        if (\is_string($value)) {
            $value = explode(',', $value);
        }
        if (!\is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $item) {
            if (\is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    private static function str(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @throws ConfigurationException
     */
    private static function int(mixed $value, int $default, string $option): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value) || (string) (int) $value !== ltrim((string) $value, '+')) {
            throw new ConfigurationException(\sprintf('"%s" must be an integer, got "%s".', $option, \is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return (int) $value;
    }

    /**
     * @throws ConfigurationException
     */
    private static function float(mixed $value, float $default, string $option): float
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new ConfigurationException(\sprintf('"%s" must be a number, got "%s".', $option, \is_scalar($value) ? (string) $value : get_debug_type($value)));
        }

        return (float) $value;
    }
}
