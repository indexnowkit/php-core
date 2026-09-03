<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Exception\ConfigurationException;

/**
 * Known IndexNow endpoints. "api" is the shared endpoint that fans out to every participant.
 */
enum Engine: string
{
    case Api = 'api';
    case Yandex = 'yandex';
    case Bing = 'bing';
    case Naver = 'naver';
    case Seznam = 'seznam';
    case Yep = 'yep';

    public function endpoint(): string
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
     * Resolve a configured engine value (enum name or full URL) into an endpoint URL.
     */
    public static function resolveEndpoint(string $value): string
    {
        $case = self::tryFrom(strtolower($value));
        if ($case !== null) {
            return $case->endpoint();
        }
        if (str_starts_with($value, 'https://') || str_starts_with($value, 'http://')) {
            return $value;
        }

        throw new ConfigurationException(\sprintf('Unknown IndexNow engine "%s". Use one of: %s, or a full endpoint URL.', $value, implode(', ', array_map(static fn(self $e) => $e->value, self::cases()))));
    }

    /**
     * Human-readable name for logs: enum value for known endpoints, host for custom ones.
     */
    public static function labelFor(string $endpoint): string
    {
        foreach (self::cases() as $case) {
            if ($case->endpoint() === $endpoint) {
                return $case->value;
            }
        }

        return (string) (parse_url($endpoint, PHP_URL_HOST) ?: $endpoint);
    }
}
