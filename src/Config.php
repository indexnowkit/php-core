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
    public const PRODUCTION_ENVIRONMENTS = ['prod', 'production'];

    /** @var list<string> engine names or endpoint URLs as configured */
    public array $engines;

    /** @var list<string> resolved, de-duplicated endpoint URLs */
    public array $endpoints;

    /** @var array<string, string> host => key */
    public array $hosts;

    /** @var array<string, string> host => key file URL (per-host overrides of key_location) */
    public array $keyLocations;

    /**
     * @param array<string, string|array{key: string, key_location?: string|null}> $hosts    per-host keys for multi-site setups
     * @param list<string>                                                          $engines  engine names ({@see Engine}) or endpoint URLs
     * @param string                                                                $dispatch adapter-defined delivery mode (sync, queue, ...); the core only reports it
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
    ) {
        if ($enabled && !$dryRun && $key === null && $hosts === []) {
            throw new ConfigurationException('IndexNow is enabled but no "key" (or "hosts" map) is configured. Set INDEXNOW_KEY, or enable dry_run.');
        }
        if ($key !== null) {
            KeyValidator::assertValid($key);
        }
        [$this->hosts, $this->keyLocations] = self::normalizeHosts($hosts);
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
        $this->engines = array_values($engines);
        $this->endpoints = array_values(array_unique(array_map(Engine::resolveEndpoint(...), $engines)));
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

        /** @var array<string, string|array{key: string, key_location?: string|null}> $hosts */
        $hosts = \is_array($data['hosts'] ?? null) ? $data['hosts'] : [];
        /** @var list<string> $engines */
        $engines = \is_array($data['engines'] ?? null) ? array_values($data['engines']) : [Engine::Api->value];
        $key = self::str($data['key'] ?? null);
        $dryRun = (bool) ($data['dry_run'] ?? false);
        $environment = self::str($data['environment'] ?? null);
        if ($key === null && $hosts === [] && $environment !== null && !\in_array(strtolower($environment), self::PRODUCTION_ENVIRONMENTS, true)) {
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
        );
    }

    /**
     * Build from environment variables.
     *
     * Recognised (with the default prefix): INDEXNOW_ENABLED, INDEXNOW_KEY, INDEXNOW_HOSTS ("host=key,host2=key2"),
     * INDEXNOW_KEY_LOCATION, INDEXNOW_BASE_URL, INDEXNOW_ENGINES ("api" or "yandex,bing"), INDEXNOW_DISPATCH,
     * INDEXNOW_BATCH_MAX_URLS, INDEXNOW_DEBOUNCE_PER_URL, INDEXNOW_THROTTLE_PER_MINUTE, INDEXNOW_HTTP_TIMEOUT,
     * INDEXNOW_USER_AGENT, INDEXNOW_SERVE_KEY_FILE, INDEXNOW_DRY_RUN, plus INDEXNOW_ENV / APP_ENV for the
     * non-production dry-run safety net.
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

        return self::fromArray(array_filter([
            'enabled' => $bool($get('ENABLED')),
            'key' => $get('KEY'),
            'hosts' => self::parseHosts($get('HOSTS')),
            'key_location' => $get('KEY_LOCATION'),
            'base_url' => $get('BASE_URL'),
            'engines' => $engines !== null ? array_values(array_filter(array_map('trim', explode(',', $engines)), static fn(string $e) => $e !== '')) : null,
            'dispatch' => $get('DISPATCH'),
            'dry_run' => $bool($get('DRY_RUN')),
            'serve_key_file' => $bool($get('SERVE_KEY_FILE')),
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
        ];
        foreach ($changes as $name => $value) {
            if (!\is_string($name) || !\array_key_exists($name, $current)) {
                throw new ConfigurationException(\sprintf('Unknown Config option "%s".', (string) $name));
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
     * @param array<string, string|array{key: string, key_location?: string|null}> $hosts
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     *
     * @throws ConfigurationException
     */
    private static function normalizeHosts(array $hosts): array
    {
        $keys = [];
        $locations = [];
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
        }

        return [$keys, $locations];
    }

    /**
     * @return array<string, string|array{key: string, key_location?: string|null}>
     */
    private function hostsForConstructor(): array
    {
        $hosts = [];
        foreach ($this->hosts as $host => $key) {
            $hosts[$host] = isset($this->keyLocations[$host]) ? ['key' => $key, 'key_location' => $this->keyLocations[$host]] : $key;
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
