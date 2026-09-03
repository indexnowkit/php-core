<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Exception\ConfigurationException;

/**
 * Known IndexNow endpoints. "api" is the shared endpoint that fans out to every participating engine,
 * so it is the right default; name engines explicitly only to reach a single one.
 */
enum Engine : string
{
    case Api = 'api';
    case Yandex = 'yandex';
    case Bing = 'bing';
    case Naver = 'naver';
    case Seznam = 'seznam';
    case Yep = 'yep';

    public function endpoint() : string
    {
        return match ($this) {
            self::Api => 'https://api.indexnow.org/indexnow',
            self::Yandex => 'https://yandex.com/indexnow',
            self::Bing => 'https://www.bing.com/indexnow',
            self::Naver => 'https://searchadvisor.naver.com/indexnow',
            self::Seznam => 'https://search.seznam.cz/indexnow',
            self::Yep => 'https://indexnow.yep.com/indexnow',
        };
    }

    /**
     * Resolve a configured engine value (case-insensitive name or full endpoint URL) into an endpoint URL.
     * Custom endpoints must use https, except on loopback hosts (mock servers).
     *
     * @throws ConfigurationException
     */
    public static function resolveEndpoint(string $value) : string
    {
        $value = trim($value);
        $case = self::tryFrom(strtolower($value));
        if ($case !== null) {
            return $case->endpoint();
        }
        $parts = parse_url($value);
        if (\is_array($parts) && isset($parts['scheme'], $parts['host'])) {
            $scheme = strtolower($parts['scheme']);
            if ($scheme === 'https' || ($scheme === 'http' && \in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true))) {
                return $value;
            }
            throw new ConfigurationException(\sprintf('Custom IndexNow endpoint "%s" must use https (the key travels in the request body).', $value));
        }

        throw new ConfigurationException(\sprintf('Unknown IndexNow engine "%s". Use one of: %s, or a full https endpoint URL.', $value, implode(', ', array_map(static fn(self $e) => $e->value, self::cases()))));
    }

    /**
     * Human-readable name for logs and results: enum value for known endpoints, host for custom ones.
     */
    public static function labelFor(string $endpoint) : string
    {
        foreach (self::cases() as $case) {
            if ($case->endpoint() === $endpoint) {
                return $case->value;
            }
        }

        return (string) (parse_url($endpoint, PHP_URL_HOST) ?: $endpoint);
    }
}
