<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Key\KeyValidator;

/**
 * Immutable configuration shared by every indexnowkit adapter. Keys mirror docs/spec/02.
 */
final readonly class Config
{
    public const DEFAULT_BATCH_MAX_URLS = 10000;
    public const DEFAULT_DEBOUNCE_PER_URL = 600;
    public const DEFAULT_THROTTLE_PER_MINUTE = 60;
    public const DEFAULT_HTTP_TIMEOUT = 10.0;

    /** @var list<string> resolved endpoint URLs */
    public array $endpoints;

    /**
     * @param array<string, string> $hosts       host => key
     * @param list<string>          $engines     engine names or endpoint URLs
     */
    public function __construct(
        public bool $enabled = true,
        public ?string $key = null,
        public array $hosts = [],
        public ?string $keyLocation = null,
        public ?string $baseUrl = null,
        array $engines = ['api'],
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
            throw new ConfigurationException('IndexNow is enabled but no "key" (or "hosts" map) is configured. Set INDEXNOW_KEY or enable dry_run.');
        }
        if ($key !== null) {
            KeyValidator::assertValid($key);
        }
        foreach ($hosts as $host => $hostKey) {
            if (!\is_string($host) || $host === '') {
                throw new ConfigurationException('"hosts" must map host names to keys.');
            }
            KeyValidator::assertValid($hostKey);
        }
        if ($baseUrl !== null && !preg_match('#^https?://[^/]+#i', $baseUrl)) {
            throw new ConfigurationException(\sprintf('"base_url" must be an absolute http(s) URL, got "%s".', $baseUrl));
        }
        if ($keyLocation !== null && !preg_match('#^https?://[^/]+/.+#i', $keyLocation)) {
            throw new ConfigurationException(\sprintf('"key_location" must be an absolute URL to the key file, got "%s".', $keyLocation));
        }
        if ($batchMaxUrls < 1 || $batchMaxUrls > self::DEFAULT_BATCH_MAX_URLS) {
            throw new ConfigurationException(\sprintf('"batch.max_urls" must be between 1 and %d.', self::DEFAULT_BATCH_MAX_URLS));
        }
        if ($debouncePerUrl < 0 || $throttleMaxRequestsPerMinute < 0 || $httpTimeout <= 0) {
            throw new ConfigurationException('"debounce.per_url" and "throttle.max_requests_per_minute" must be >= 0, "http.timeout" must be > 0.');
        }
        if ($engines === []) {
            throw new ConfigurationException('"engines" must contain at least one engine.');
        }
        $this->endpoints = array_values(array_unique(array_map(Engine::resolveEndpoint(...), $engines)));
    }

    /**
     * Build from the canonical nested array shape used by framework configs.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $batch = self::sub($data, 'batch');
        $debounce = self::sub($data, 'debounce');
        $throttle = self::sub($data, 'throttle');
        $http = self::sub($data, 'http');

        /** @var array<string, string> $hosts */
        $hosts = \is_array($data['hosts'] ?? null) ? $data['hosts'] : [];
        /** @var list<string> $engines */
        $engines = \is_array($data['engines'] ?? null) ? array_values($data['engines']) : ['api'];
        $key = $data['key'] ?? null;

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            key: \is_string($key) && $key !== '' ? $key : null,
            hosts: $hosts,
            keyLocation: self::str($data['key_location'] ?? null),
            baseUrl: self::str($data['base_url'] ?? null),
            engines: $engines,
            dispatch: self::str($data['dispatch'] ?? null) ?? 'sync',
            batchMaxUrls: self::int($batch['max_urls'] ?? null, self::DEFAULT_BATCH_MAX_URLS),
            debouncePerUrl: self::int($debounce['per_url'] ?? null, self::DEFAULT_DEBOUNCE_PER_URL),
            throttleMaxRequestsPerMinute: self::int($throttle['max_requests_per_minute'] ?? null, self::DEFAULT_THROTTLE_PER_MINUTE),
            httpTimeout: self::float($http['timeout'] ?? null, self::DEFAULT_HTTP_TIMEOUT),
            userAgent: self::str($http['user_agent'] ?? null),
            serveKeyFile: (bool) ($data['serve_key_file'] ?? true),
            dryRun: (bool) ($data['dry_run'] ?? false),
        );
    }

    /**
     * Build from environment variables (INDEXNOW_KEY, INDEXNOW_BASE_URL, INDEXNOW_ENGINES, INDEXNOW_DRY_RUN ...).
     *
     * @param array<string, mixed>|null $env defaults to $_ENV + $_SERVER + getenv()
     */
    public static function fromEnv(?array $env = null, string $prefix = 'INDEXNOW_'): self
    {
        $env ??= array_merge(getenv(), $_SERVER, $_ENV);
        $get = static fn(string $name): ?string => isset($env[$prefix . $name]) && \is_scalar($env[$prefix . $name]) ? (string) $env[$prefix . $name] : null;
        $engines = $get('ENGINES');

        return self::fromArray(array_filter([
            'enabled' => $get('ENABLED') === null ? true : filter_var($get('ENABLED'), FILTER_VALIDATE_BOOL),
            'key' => $get('KEY'),
            'key_location' => $get('KEY_LOCATION'),
            'base_url' => $get('BASE_URL'),
            'engines' => $engines !== null ? array_map('trim', explode(',', $engines)) : null,
            'dispatch' => $get('DISPATCH'),
            'dry_run' => $get('DRY_RUN') !== null ? filter_var($get('DRY_RUN'), FILTER_VALIDATE_BOOL) : null,
            'debounce' => $get('DEBOUNCE_PER_URL') !== null ? ['per_url' => (int) $get('DEBOUNCE_PER_URL')] : null,
            'http' => $get('HTTP_TIMEOUT') !== null ? ['timeout' => (float) $get('HTTP_TIMEOUT')] : null,
        ], static fn($v) => $v !== null));
    }

    public function withDryRun(bool $dryRun): self
    {
        return new self($this->enabled, $this->key, $this->hosts, $this->keyLocation, $this->baseUrl, $this->endpoints, $this->dispatch, $this->batchMaxUrls, $this->debouncePerUrl, $this->throttleMaxRequestsPerMinute, $this->httpTimeout, $this->userAgent, $this->serveKeyFile, $dryRun);
    }

    public function userAgent(): string
    {
        return $this->userAgent ?? 'indexnowkit-php/' . Version::VERSION . ' (+https://indexnowkit.dev)';
    }

    /**
     * @param array<string, mixed> $data
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

    private static function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private static function float(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
