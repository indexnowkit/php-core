<?php

declare(strict_types=1);

namespace IndexNowKit\Config;

use IndexNowKit\Config;
use IndexNowKit\Engine;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Key\KeyFileResponder;

/**
 * The two readers of `Config`: the canonical nested array of the framework configurations ({@see fromArray()}, the body
 * of `Config::fromArray()`) and the `INDEXNOW_*` environment variables ({@see fromEnv()}, the body of `Config::fromEnv()`),
 * with the scalar parsers the keys share (`"3"`, `"true"`, `"1.5"` and `""` for "not set"). Reading only: the values go to
 * the constructor of `Config`, which checks the invariants.
 *
 * @internal part of `Config`, not of the compatibility promise (docs/bc.md); call `Config::fromArray()` / `fromEnv()`
 */
final class ConfigParser
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws ConfigurationException
     */
    public static function fromArray(array $data): Config
    {
        $batch = self::sub($data, 'batch');
        $debounce = self::sub($data, 'debounce');
        $throttle = self::sub($data, 'throttle');
        $http = self::sub($data, 'http');
        $logging = self::sub($data, 'logging');
        $retry = self::sub($data, 'retry');
        $resolver = self::sub($data, 'resolver');
        $collector = self::sub($data, 'collector');
        $keyFile = self::sub($data, 'key_file');
        $normalizer = self::sub($data, 'normalizer');
        $serveKeyFile = self::serveKeyFileFrom($data);
        $trackingParams = self::list($normalizer['tracking_params'] ?? null) ?? [];
        /** @var array<mixed, mixed> $logLevels */
        $logLevels = \is_array($logging['levels'] ?? null) ? $logging['levels'] : [];
        /** @var array<mixed, mixed> $engineAliases */
        $engineAliases = \is_array($data['engine_aliases'] ?? null) ? $data['engine_aliases'] : [];
        /** @var array<mixed, mixed> $localeHosts */
        $localeHosts = \is_array($data['locale_hosts'] ?? null) ? $data['locale_hosts'] : [];
        $productionEnvironments = self::list($data['production_environments'] ?? null) ?? Config::PRODUCTION_ENVIRONMENTS;

        /** @var array<string, string|array{key: string, key_location?: string|null, base_url?: string|null, engines?: list<string>|null, previous_key?: string|null}> $hosts */
        $hosts = \is_array($data['hosts'] ?? null) ? $data['hosts'] : [];
        /** @var list<string> $engines */
        $engines = \is_array($data['engines'] ?? null) ? array_values($data['engines']) : [Engine::Api->value];
        $key = self::str($data['key'] ?? null);
        $dryRunValue = self::bool($data['dry_run'] ?? null, null, 'dry_run');
        $dryRun = $dryRunValue ?? false;
        $dryRunExplicit = $dryRunValue !== null; // null and '' (an unset environment variable) are "not set"
        $environment = self::str($data['environment'] ?? null);
        if ($key === null && $hosts === [] && $environment !== null && !\in_array(strtolower($environment), array_map('strtolower', $productionEnvironments), true)) {
            $dryRun = true;
        }

        return new Config(
            enabled: self::bool($data['enabled'] ?? null, true, 'enabled') ?? true,
            key: $key,
            hosts: $hosts,
            keyLocation: self::str($data['key_location'] ?? null),
            baseUrl: self::str($data['base_url'] ?? null),
            engines: $engines,
            dispatch: self::str($data['dispatch'] ?? null) ?? 'sync',
            batchMaxUrls: self::int($batch['max_urls'] ?? null, Config::DEFAULT_BATCH_MAX_URLS, 'batch.max_urls'),
            debouncePerUrl: self::int($debounce['per_url'] ?? null, Config::DEFAULT_DEBOUNCE_PER_URL, 'debounce.per_url'),
            throttleMaxRequestsPerMinute: self::int($throttle['max_requests_per_minute'] ?? null, Config::DEFAULT_THROTTLE_PER_MINUTE, 'throttle.max_requests_per_minute'),
            httpTimeout: self::float($http['timeout'] ?? null, Config::DEFAULT_HTTP_TIMEOUT, 'http.timeout'),
            userAgent: self::str($http['user_agent'] ?? null),
            serveKeyFile: $serveKeyFile,
            dryRun: $dryRun,
            strictHosts: self::bool($data['strict_hosts'] ?? null, false, 'strict_hosts') ?? false,
            environment: $environment,
            productionEnvironments: $productionEnvironments,
            maxUrlLength: self::int($data['max_url_length'] ?? null, Config::DEFAULT_MAX_URL_LENGTH, 'max_url_length'),
            logUrls: self::int($logging['max_urls'] ?? null, Config::DEFAULT_LOG_URLS, 'logging.max_urls'),
            forbiddenEscalation: self::int($logging['forbidden_escalation'] ?? null, Config::DEFAULT_FORBIDDEN_ESCALATION, 'logging.forbidden_escalation'),
            retryMaxAttempts: self::int($retry['max_attempts'] ?? null, Config::DEFAULT_RETRY_MAX_ATTEMPTS, 'retry.max_attempts'),
            retryBaseDelay: self::int($retry['base_delay'] ?? null, Config::DEFAULT_RETRY_BASE_DELAY, 'retry.base_delay'),
            retryMultiplier: self::float($retry['multiplier'] ?? null, Config::DEFAULT_RETRY_MULTIPLIER, 'retry.multiplier'),
            retryMaxDelay: self::int($retry['max_delay'] ?? null, Config::DEFAULT_RETRY_MAX_DELAY, 'retry.max_delay'),
            retryServerErrorDelay: self::int($retry['server_error_delay'] ?? null, Config::DEFAULT_RETRY_SERVER_ERROR_DELAY, 'retry.server_error_delay'),
            previousKey: self::str($data['previous_key'] ?? null),
            logLevels: $logLevels,
            resolverMaxViaDepth: self::int($resolver['max_via_depth'] ?? null, Config::DEFAULT_RESOLVER_MAX_VIA_DEPTH, 'resolver.max_via_depth'),
            resolverMaxViaFanout: self::int($resolver['max_via_fanout'] ?? null, Config::DEFAULT_RESOLVER_MAX_VIA_FANOUT, 'resolver.max_via_fanout'),
            debounceKeyPrefix: self::str($debounce['key_prefix'] ?? null) ?? Config::DEFAULT_DEBOUNCE_KEY_PREFIX,
            collectorMaxUrls: self::int($collector['max_urls'] ?? null, 0, 'collector.max_urls'),
            collectorDetectLeaks: self::bool($collector['detect_leaks'] ?? null, true, 'collector.detect_leaks') ?? true,
            logBody: self::int($logging['max_body'] ?? null, Config::DEFAULT_LOG_BODY, 'logging.max_body'),
            engineAliases: $engineAliases,
            localeHosts: $localeHosts,
            keyFileMaxAge: self::int($keyFile['cache_max_age'] ?? null, KeyFileResponder::DEFAULT_MAX_AGE, 'key_file.cache_max_age'),
            debounceStore: self::str($debounce['store'] ?? null),
            httpClient: self::str($http['client'] ?? null),
            dryRunExplicit: $dryRunExplicit,
            normalizerStripTrackingParams: self::bool($normalizer['strip_tracking_params'] ?? null, true, 'normalizer.strip_tracking_params') ?? true,
            normalizerTrackingParams: $trackingParams,
            normalizerTrailingSlash: self::str($normalizer['trailing_slash'] ?? null) ?? Config::DEFAULT_TRAILING_SLASH,
            normalizerSortQuery: self::bool($normalizer['sort_query'] ?? null, false, 'normalizer.sort_query') ?? false,
        );
    }

    /**
     * The explicit `serve_key_file` (the pre-0.4 name) wins over `key_file.enabled`, then the default is true.
     *
     * @param array<string, mixed> $data
     *
     * @throws ConfigurationException when either value is not a boolean
     */
    public static function serveKeyFileFrom(array $data): bool
    {
        $keyFile = self::sub($data, 'key_file');

        return self::bool($data['serve_key_file'] ?? null, null, 'serve_key_file') ?? self::bool($keyFile['enabled'] ?? null, null, 'key_file.enabled') ?? true;
    }

    /**
     * The `INDEXNOW_*` variables (the list is on `Config::fromEnv()`) as a Config: {@see fromArray()} of {@see envArray()}.
     *
     * @param array<string, mixed> $env
     *
     * @throws ConfigurationException
     */
    public static function fromEnv(array $env, string $prefix): Config
    {
        return self::fromArray(self::envArray($env, $prefix));
    }

    /**
     * The `INDEXNOW_*` variables to the nested array of {@see fromArray()}, only the variables that are set: an unset
     * (or empty) variable leaves no key, so the array merges over a configuration file without its defaults getting in
     * the way (the body of `Config::arrayFromEnv()`). Values stay strings; `fromArray()` coerces them.
     *
     * @param array<string, mixed> $env
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationException on an `INDEXNOW_HOSTS` entry without `=`
     */
    public static function envArray(array $env, string $prefix): array
    {
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

        return array_filter([
            'enabled' => $bool($get('ENABLED')),
            'key' => $get('KEY'),
            'previous_key' => $get('PREVIOUS_KEY'),
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
            'debounce' => ($debounce = array_filter(['per_url' => $get('DEBOUNCE_PER_URL'), 'store' => $get('DEBOUNCE_STORE')], static fn($v) => $v !== null)) === [] ? null : $debounce,
            'throttle' => $get('THROTTLE_PER_MINUTE') !== null ? ['max_requests_per_minute' => $get('THROTTLE_PER_MINUTE')] : null,
            'http' => ($http = array_filter(['timeout' => $get('HTTP_TIMEOUT'), 'user_agent' => $get('USER_AGENT'), 'client' => $get('HTTP_CLIENT')], static fn($v) => $v !== null)) === [] ? null : $http,
            'key_file' => ($keyFile = array_filter(['enabled' => $bool($get('KEY_FILE_ENABLED')), 'cache_max_age' => $get('KEY_FILE_CACHE_MAX_AGE')], static fn($v) => $v !== null)) === [] ? null : $keyFile,
        ], static fn($v) => $v !== null);
    }

    /**
     * `INDEXNOW_HOSTS`: "host=key,host2=key2".
     *
     * @return array<string, string>|null
     *
     * @throws ConfigurationException
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

    /**
     * A nested block of the array, or an empty array when the key is missing or not an array.
     *
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
     * A list of trimmed, non-empty strings from an array or a comma-separated string; null when neither.
     *
     * @return list<string>|null
     */
    public static function list(mixed $value): ?array
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

    /** A non-empty string, else null ("" is "not set"). */
    private static function str(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * "true"/"false"/"1"/"0"/"yes"/"no" and the like as booleans; any other scalar by PHP truthiness.
     *
     * @throws ConfigurationException
     */
    private static function bool(mixed $value, ?bool $default, string $option): ?bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (\is_bool($value)) {
            return $value;
        }
        if (!\is_scalar($value)) {
            throw new ConfigurationException(\sprintf('"%s" must be a boolean, got %s.', $option, get_debug_type($value)));
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
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
