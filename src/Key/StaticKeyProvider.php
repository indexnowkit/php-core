<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

use IndexNowKit\Config;

/**
 * Default key per host with optional per-host overrides. Without overrides every host uses the default key.
 */
final readonly class StaticKeyProvider implements KeyProviderInterface
{
    /** @var array<string, string> lower-cased host => key */
    private array $hosts;

    /**
     * @param array<string, string> $hosts
     */
    public function __construct(
        private ?string $defaultKey,
        array $hosts = [],
        private ?string $keyLocation = null,
    ) {
        $normalized = [];
        foreach ($hosts as $host => $key) {
            $normalized[strtolower($host)] = $key;
        }
        $this->hosts = $normalized;
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->key, $config->hosts, $config->keyLocation);
    }

    public function keyFor(string $host): ?string
    {
        return $this->hosts[strtolower($host)] ?? $this->defaultKey;
    }

    public function keyLocationFor(string $host): ?string
    {
        return $this->keyLocation;
    }

    public function isKnownKey(string $key): bool
    {
        return $key === $this->defaultKey || \in_array($key, $this->hosts, true);
    }
}
