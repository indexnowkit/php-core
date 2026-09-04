<?php

declare(strict_types=1);

namespace IndexNowKit\Testing;

use IndexNowKit\Key\KeyFileResponder;
use PHPUnit\Framework\Assert;

/**
 * What a key file response must look like (conformance H01–H03), for the HTTP test of any adapter: parse the
 * framework's response into status, headers and body, and assert here. `Cache-Control` is compared by directive,
 * not as a string, because frameworks order directives differently (`max-age=300, public` vs `public, max-age=300`).
 */
final class KeyFileAssertions
{
    private function __construct() {}

    /**
     * A served key file: 200, `text/plain; charset=utf-8`, the key as the whole body, `Cache-Control` with
     * `public` and the configured `max-age`, and `Vary: Host` exactly when the application serves several hosts
     * (a `hosts` map), never otherwise.
     *
     * @param array<string, string|list<string>> $headers response headers, names in any case, one value or a list
     */
    public static function assertKeyFileResponse(int $status, array $headers, string $body, string $key, int $maxAge = KeyFileResponder::DEFAULT_MAX_AGE, bool $expectVaryHost = false): void
    {
        Assert::assertSame(200, $status, 'the key file answers 200');
        Assert::assertSame($key, $body, 'the body is the key and nothing else');
        Assert::assertSame(KeyFileResponder::CONTENT_TYPE, self::header($headers, 'Content-Type'), 'Content-Type');

        $directives = self::cacheControl(self::header($headers, 'Cache-Control') ?? '');
        Assert::assertContains('public', $directives, 'Cache-Control: public (CDNs and proxies may cache the key file)');
        Assert::assertContains('max-age=' . $maxAge, $directives, \sprintf('Cache-Control: max-age=%d (key_file.cache_max_age)', $maxAge));

        $vary = self::header($headers, 'Vary');
        if ($expectVaryHost) {
            Assert::assertNotNull($vary, 'Vary: Host — the body depends on the host with a hosts map');
            Assert::assertContains('host', array_map(static fn(string $v): string => strtolower(trim($v)), explode(',', $vary)), 'Vary includes Host');
        } else {
            Assert::assertNull($vary, 'no Vary header without a hosts map');
        }
    }

    /**
     * A key file that must not be served: an unknown key, another host's key, `key_file.enabled: false`.
     */
    public static function assertNotServed(int $status): void
    {
        Assert::assertSame(404, $status, 'a key file that is not served answers 404, nothing else');
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $header => $value) {
            if (strcasecmp((string) $header, $name) === 0) {
                return \is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return null;
    }

    /**
     * @return list<string> lower-cased directives, whitespace stripped
     */
    private static function cacheControl(string $value): array
    {
        return array_values(array_filter(array_map(static fn(string $d): string => strtolower(trim($d)), explode(',', $value)), static fn(string $d): bool => $d !== ''));
    }
}
