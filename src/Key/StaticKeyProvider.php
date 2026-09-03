<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

use IndexNowKit\Config;

/**
 * Keys from configuration: a per-host map plus a default key.
 *
 * The default key applies to every host not in the map (single-site setups usually only set it). Without a
 * default key, or with `$strictHosts`, hosts missing from the map (and different from the base host) are
 * unmanaged: their URLs are skipped, never sent under another host's key.
 */
final readonly class StaticKeyProvider implements KeyProviderInterface
{
    /** @var array<string, string> lower-cased host => key */
    private array $hosts;

    /** @var array<string, string> lower-cased host => key file URL */
    private array $keyLocations;

    private ?string $defaultHost;

    /**
     * @param array<string, string> $hosts
     * @param array<string, string> $keyLocations per-host overrides of $keyLocation
     * @param bool                  $strictHosts  apply the default key only to $defaultHost (multi-domain setups)
     */
    public function __construct(
        private ?string $defaultKey,
        array $hosts = [],
        private ?string $keyLocation = null,
        ?string $defaultHost = null,
        array $keyLocations = [],
        private bool $strictHosts = false,
    ) {
        $this->hosts = array_change_key_case($hosts, CASE_LOWER);
        $this->keyLocations = array_change_key_case($keyLocations, CASE_LOWER);
        $this->defaultHost = $defaultHost !== null ? strtolower($defaultHost) : null;
    }

    public static function fromConfig(Config $config): self
    {
        return new self($config->key, $config->hosts, $config->keyLocation, $config->baseHost(), $config->keyLocations, $config->strictHosts);
    }

    public function keyFor(string $host): ?string
    {
        $host = strtolower($host);
        if (isset($this->hosts[$host])) {
            return $this->hosts[$host];
        }
        if ($this->strictHosts && $host !== $this->defaultHost) {
            return null;
        }

        return $this->defaultKey;
    }

    public function keyLocationFor(string $host): ?string
    {
        $host = strtolower($host);
        if (isset($this->keyLocations[$host])) {
            return $this->keyLocations[$host];
        }
        if (isset($this->hosts[$host])) {
            return null; // mapped host without an override: key file at the default location
        }

        return $this->keyLocation;
    }

    public function isKnownKey(string $key, ?string $host = null): bool
    {
        if ($host !== null) {
            $expected = $this->keyFor($host);

            return $expected !== null && hash_equals($expected, $key);
        }
        if ($this->defaultKey !== null && hash_equals($this->defaultKey, $key)) {
            return true;
        }
        foreach ($this->hosts as $hostKey) {
            if (hash_equals($hostKey, $key)) {
                return true;
            }
        }

        return false;
    }

    public function managedHosts(): array
    {
        $hosts = array_keys($this->hosts);
        if ($this->defaultHost !== null && $this->defaultKey !== null) {
            $hosts[] = $this->defaultHost;
        }

        return array_values(array_unique($hosts));
    }
}
