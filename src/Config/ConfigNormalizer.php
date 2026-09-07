<?php

declare(strict_types=1);

namespace IndexNowKit\Config;

use IndexNowKit\Engine;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Key\KeyValidator;

/**
 * The raw arrays the constructor of `Config` accepts (`hosts`, `engine_aliases`, `locale_hosts`, `logging.levels`,
 * `normalizer.tracking_params`, `production_environments`) to their validated, lower-cased shape, plus the URL predicates
 * the constructor and those checks share. Pure static functions: every one either returns the normalised value or throws
 * a ConfigurationException naming the option.
 *
 * @internal part of `Config`, not of the compatibility promise (docs/bc.md)
 */
final class ConfigNormalizer
{
    /** Bare host name: labels, or an IPv6 literal in brackets; no scheme, port or path. */
    private const HOST_PATTERN = '/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)$/i';

    /**
     * @param array<mixed, mixed> $params
     *
     * @return list<string>
     *
     * @throws ConfigurationException
     */
    public static function trackingParams(array $params): array
    {
        $out = [];
        foreach ($params as $name) {
            if (!\is_string($name) || preg_match('/^[A-Za-z0-9_.\-\[\]]+\*?$/', trim($name)) !== 1) {
                throw new ConfigurationException(\sprintf('"normalizer.tracking_params" must list query parameter names ("ref", "mtm_*"), got %s.', self::describe($name)));
            }
            $out[] = strtolower(trim($name));
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<mixed, mixed> $aliases
     *
     * @return array<string, string> lower-cased alias => endpoint
     *
     * @throws ConfigurationException
     */
    public static function engineAliases(array $aliases): array
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
     * @return array<string, string> lower-cased locale => lower-cased host
     *
     * @throws ConfigurationException
     */
    public static function localeHosts(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $locale => $host) {
            if (!\is_string($locale) || $locale === '' || !\is_string($host) || preg_match(self::HOST_PATTERN, $host) !== 1) {
                throw new ConfigurationException(\sprintf('"locale_hosts" must map locales to bare host names, got "%s" => %s.', (string) $locale, self::describe($host)));
            }
            $out[strtolower($locale)] = strtolower($host);
        }

        return $out;
    }

    /**
     * @param array<mixed, mixed>   $levels
     * @param array<string, string> $events the known log events => shipped level (`Config::LOG_EVENTS`)
     * @param list<string>          $known  the PSR-3 level names
     *
     * @return array<string, string> event => lower-cased level
     *
     * @throws ConfigurationException
     */
    public static function logLevels(array $levels, array $events, array $known): array
    {
        $out = [];
        foreach ($levels as $event => $level) {
            if (!\is_string($event) || !isset($events[$event])) {
                throw new ConfigurationException(\sprintf('"logging.levels" has an unknown event "%s"; known: %s.', (string) $event, implode(', ', array_keys($events))));
            }
            if (!\is_string($level) || !\in_array(strtolower($level), $known, true)) {
                throw new ConfigurationException(\sprintf('"logging.levels.%s" must be a PSR-3 level (%s), got %s.', $event, implode(', ', $known), self::describe($level)));
            }
            $out[$event] = strtolower($level);
        }

        return $out;
    }

    /**
     * @param array<mixed, mixed> $environments
     *
     * @return list<string> lower-cased, trimmed, unique; at least one
     *
     * @throws ConfigurationException
     */
    public static function productionEnvironments(array $environments): array
    {
        $out = array_values(array_unique(array_map(static fn(string $e): string => strtolower(trim($e)), array_filter($environments, static fn(mixed $e): bool => \is_string($e) && trim($e) !== ''))));
        if ($out === []) {
            throw new ConfigurationException('"production_environments" must name at least one environment.');
        }

        return $out;
    }

    /**
     * The `hosts` map to its five per-host tables: keys, key file URLs, base URLs, engine lists, previous keys.
     *
     * @param array<mixed, mixed> $hosts host => key string, or `{key, key_location?, base_url?, engines?, previous_key?}`
     *
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, string>, 3: array<string, list<string>>, 4: array<string, string>}
     *
     * @throws ConfigurationException
     */
    public static function hosts(array $hosts): array
    {
        $keys = [];
        $locations = [];
        $baseUrls = [];
        $engines = [];
        $previous = [];
        foreach ($hosts as $host => $entry) {
            if (!\is_string($host) || $host === '' || preg_match(self::HOST_PATTERN, $host) !== 1) {
                throw new ConfigurationException(\sprintf('"hosts" must map bare host names (no scheme, port or path) to keys, got "%s".', (string) $host));
            }
            $host = strtolower($host);
            $key = \is_array($entry) ? ($entry['key'] ?? '') : $entry;
            if (!\is_string($key)) {
                throw new ConfigurationException(\sprintf('"hosts.%s" must be a key string or {key, key_location}.', $host));
            }
            KeyValidator::assertValid($key);
            $keys[$host] = $key;
            $location = self::hostUrl($entry, 'key_location', $host, true);
            if ($location !== null) {
                $locations[$host] = $location;
            }
            $baseUrl = self::hostUrl($entry, 'base_url', $host, false);
            if ($baseUrl !== null) {
                $baseUrls[$host] = $baseUrl;
            }
            $hostEngines = \is_array($entry) ? ($entry['engines'] ?? null) : null;
            if ($hostEngines !== null) {
                $list = ConfigParser::list($hostEngines);
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
     * A URL entry of a host (`key_location`, `base_url`): absolute, http(s), on that very host.
     *
     * @throws ConfigurationException
     */
    private static function hostUrl(mixed $entry, string $option, string $host, bool $keyFile): ?string
    {
        $url = \is_array($entry) ? ($entry[$option] ?? null) : null;
        if ($url === null) {
            return null;
        }
        if (!\is_string($url) || !($keyFile ? self::isKeyFileUrl($url) : self::isAbsoluteHttpUrl($url))) {
            throw new ConfigurationException(\sprintf('"hosts.%s.%s" must be an absolute http(s) URL.', $host, $option));
        }
        if (self::hostOf($url) !== $host) {
            throw new ConfigurationException(\sprintf('"hosts.%s.%s" must be on host %s, got %s.', $host, $option, $host, self::hostOf($url)));
        }

        return $url;
    }

    /** Absolute http(s) URL with a host and without userinfo. */
    public static function isAbsoluteHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return \is_array($parts) && isset($parts['scheme'], $parts['host']) && \in_array(strtolower($parts['scheme']), ['http', 'https'], true) && !isset($parts['user']) && !isset($parts['pass']);
    }

    /** Lower-cased host of a URL, empty when there is none. */
    public static function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return \is_string($host) ? strtolower($host) : '';
    }

    /** An absolute http(s) URL with a path other than "/": where a key file can live. */
    public static function isKeyFileUrl(string $url): bool
    {
        return self::isAbsoluteHttpUrl($url) && \is_string(parse_url($url, PHP_URL_PATH)) && parse_url($url, PHP_URL_PATH) !== '/';
    }

    /** A value for an error message: quoted when scalar, its type otherwise. */
    private static function describe(mixed $value): string
    {
        return \is_scalar($value) ? '"' . (string) $value . '"' : get_debug_type($value);
    }
}
