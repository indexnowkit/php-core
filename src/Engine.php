<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Exception\ConfigurationException;

/**
 * Known IndexNow endpoints. "api" is the shared endpoint that fans out to every participating engine,
 * so it is the right default; name engines explicitly only to reach a single one.
 *
 * The list follows https://www.indexnow.org/searchengines.json and each engine's `meta.json` (snapshot
 * 2026-09-05: bing, yandex, seznam, naver, yep, internetarchive, amazonbot). Endpoints are the `api` field of
 * the meta files; Yandex answers on both `yandex.com` and `www.yandex.com` without a redirect.
 */
enum Engine: string
{
    case Api = 'api';
    case Yandex = 'yandex';
    case Bing = 'bing';
    case Naver = 'naver';
    case Seznam = 'seznam';
    case Yep = 'yep';
    /** Internet Archive (Wayback Machine). Its meta.json declares the endpoint below; the host did not resolve on 2026-09-05 — reach it through `api`. */
    case InternetArchive = 'internetarchive';
    /** Amazonbot (registry id `amazonbot`). */
    case Amazon = 'amazon';

    public function endpoint(): string
    {
        return match ($this) {
            self::Api => 'https://api.indexnow.org/indexnow',
            self::Yandex => 'https://yandex.com/indexnow',
            self::Bing => 'https://www.bing.com/indexnow',
            self::Naver => 'https://searchadvisor.naver.com/indexnow',
            self::Seznam => 'https://search.seznam.cz/indexnow',
            self::Yep => 'https://indexnow.yep.com/indexnow',
            self::InternetArchive => 'https://internetarchive.indexnow.org/indexnow',
            self::Amazon => 'https://indexnow.amazonbot.amazon/indexnow',
        };
    }

    /**
     * Resolve a configured engine value (case-insensitive name or full endpoint URL) into an endpoint URL.
     * Custom endpoints must use https, except on loopback hosts (mock servers).
     *
     * @throws ConfigurationException
     */
    public static function resolveEndpoint(string $value): string
    {
        $value = trim($value);
        $case = self::tryFrom(strtolower($value));
        if ($case !== null) {
            return $case->endpoint();
        }
        $parts = parse_url($value);
        if (\is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            if (isset($parts['user']) || isset($parts['pass'])) {
                throw new ConfigurationException(\sprintf('Custom IndexNow endpoint "%s" must not contain credentials.', $value));
            }
            $scheme = strtolower($parts['scheme']);
            $host = strtolower($parts['host']);
            if ($scheme === 'https' || ($scheme === 'http' && \in_array($host, ['localhost', '127.0.0.1', '[::1]'], true))) {
                return $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            }
            throw new ConfigurationException(\sprintf('Custom IndexNow endpoint "%s" must use https (the key travels in the request body).', $value));
        }

        throw new ConfigurationException(\sprintf('Unknown IndexNow engine "%s". Use one of: %s, an alias from engine_aliases, or a full https endpoint URL.', $value, implode(', ', array_map(static fn(self $e) => $e->value, self::cases()))));
    }

    /**
     * Human-readable name for logs and results: enum value for known endpoints, host for custom ones.
     */
    public static function labelFor(string $endpoint): string
    {
        foreach (self::cases() as $case) {
            if ($case->endpoint() === $endpoint) {
                return $case->value;
            }
        }

        $host = parse_url($endpoint, PHP_URL_HOST);

        return \is_string($host) && $host !== '' ? $host : $endpoint;
    }
}
