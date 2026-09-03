<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Url\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: string}>
     */
    public static function acceptedProvider(): iterable
    {
        yield 'scheme and host lower-cased' => ['HTTP://EXAMPLE.COM/x', null, 'http://example.com/x'];
        yield 'default http port stripped' => ['http://example.com:80/x', null, 'http://example.com/x'];
        yield 'default https port stripped' => ['https://example.com:443/x', null, 'https://example.com/x'];
        yield 'non-default port kept' => ['http://example.com:8080/x', null, 'http://example.com:8080/x'];
        yield 'fragment stripped' => ['https://example.com/x#top', null, 'https://example.com/x'];
        yield 'query kept' => ['https://example.com/x?a=1&b=2', null, 'https://example.com/x?a=1&b=2'];
        yield 'IDN host to punycode' => ['https://münchen.de/x', null, 'https://xn--mnchen-3ya.de/x'];
        yield 'trailing dot on host removed' => ['https://example.com./x', null, 'https://example.com/x'];
        yield 'dot-segments resolved' => ['https://example.com/a/./b/../c', null, 'https://example.com/a/c'];
        yield 'spaces percent-encoded' => ['https://example.com/a b', null, 'https://example.com/a%20b'];
        yield 'protocol-relative without base uses https' => ['//example.com/x', null, 'https://example.com/x'];
        yield 'protocol-relative with base uses base scheme' => ['//example.com/x', 'http://base.test', 'http://example.com/x'];
        yield 'relative with base_url path is appended' => ['page', 'https://example.com/blog', 'https://example.com/blog/page'];
        yield 'absolute path with base_url ignores base path' => ['/x', 'https://h.example.com/blog', 'https://h.example.com/x'];
        yield 'IPv6 host kept with non-default port' => ['https://[::1]:8443/x', null, 'https://[::1]:8443/x'];
    }

    #[DataProvider('acceptedProvider')]
    public function testNormalizeAcceptedUrls(string $url, ?string $baseUrl, string $expected): void
    {
        self::assertSame($expected, (new UrlNormalizer($baseUrl))->normalize($url));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'ftp scheme' => ['ftp://example.com/a'];
        yield 'mailto' => ['mailto:a@example.com'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'credentials' => ['https://user:pass@example.com/'];
        yield 'control characters' => ["https://example.com/\x01a"];
        yield 'longer than 2048 bytes' => ['https://example.com/' . str_repeat('a', 2048)];
        yield 'host label longer than 63 chars' => ['https://' . str_repeat('a', 64) . '.com/x'];
        yield 'host longer than 253 chars' => ['https://' . implode('.', array_fill(0, 40, str_repeat('a', 6))) . '.com/x'];
        yield 'invalid host characters' => ['https://exa_mple.com/x'];
        yield 'invalid utf-8 bytes' => ["https://example.com/\xB1\x31"];
        yield 'relative without base_url' => ['/x'];
    }

    #[DataProvider('rejectedProvider')]
    public function testNormalizeRejectsInvalidUrls(string $url): void
    {
        $this->expectException(InvalidUrlException::class);
        (new UrlNormalizer())->normalize($url);
    }

    public function testHostOfLowerCasesHost(): void
    {
        $normalizer = new UrlNormalizer();
        self::assertSame('example.com', $normalizer->hostOf($normalizer->normalize('https://EXAMPLE.com/x')));
    }

    public function testHostOfThrowsWhenNoHost(): void
    {
        $this->expectException(InvalidUrlException::class);
        (new UrlNormalizer())->hostOf('/just/a/path');
    }
}
