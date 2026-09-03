<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

/**
 * Framework-agnostic key-file endpoint: the adapter matches the request path, this class decides the answer.
 * Serve the body with HTTP 200, {@see CONTENT_TYPE} and no redirect; answer 404 when it returns null
 * (conformance H01-H03).
 */
final readonly class KeyFileResponder
{
    /** Request path pattern; group 1 is the key. */
    public const PATH_PATTERN = '#^/([' . KeyValidator::ALPHABET . ']{' . KeyValidator::MIN_LENGTH . ',' . KeyValidator::MAX_LENGTH . '})\.txt$#';
    public const CONTENT_TYPE = 'text/plain; charset=utf-8';
    /** Keep it short: after a key rotation a cached old file makes every submission fail with 403. */
    public const DEFAULT_MAX_AGE = 300;

    public function __construct(private KeyProviderInterface $keys, private bool $enabled = true) {}

    /**
     * Body to serve for a request path (`/abc...123.txt`), or null for 404.
     *
     * @param string|null $host host the request arrived at (see KeyProviderInterface::isKnownKey())
     */
    public function bodyForPath(string $path, ?string $host = null): ?string
    {
        if (preg_match(self::PATH_PATTERN, $path, $m) !== 1) {
            return null;
        }

        return $this->bodyForKey($m[1], $host);
    }

    /**
     * Body to serve for an already extracted key, or null for 404.
     */
    public function bodyForKey(string $key, ?string $host = null): ?string
    {
        if (!$this->enabled || !KeyValidator::isValid($key) || !$this->keys->isKnownKey($key, $host)) {
            return null;
        }

        return $key;
    }

    /**
     * @param bool $varyHost add `Vary: Host` (multi-domain setups behind one shared cache: the body depends on the host)
     *
     * @return array<string, string>
     */
    public static function headers(int $maxAge = self::DEFAULT_MAX_AGE, bool $varyHost = false): array
    {
        $headers = ['Content-Type' => self::CONTENT_TYPE, 'Cache-Control' => \sprintf('public, max-age=%d', max(0, $maxAge))];
        if ($varyHost) {
            $headers['Vary'] = 'Host';
        }

        return $headers;
    }
}
