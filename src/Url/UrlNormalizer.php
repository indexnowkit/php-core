<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Exception\InvalidUrlException;

final class UrlNormalizer
{
    public function __construct(private readonly ?string $baseUrl = null) {}

    /**
     * Absolute, RFC 3986-normalized URL without fragment; IDN host converted to punycode.
     * Relative input is resolved against base_url.
     */
    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidUrlException('Empty URL.');
        }
        if (!preg_match('#^https?://#i', $url)) {
            if ($this->baseUrl === null) {
                throw new InvalidUrlException(\sprintf('Relative URL "%s" given but no base_url configured.', $url));
            }
            $url = rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
            throw new InvalidUrlException(\sprintf('Cannot parse URL "%s".', $url));
        }

        $host = Punycode::encodeHost(strtolower($parts['host']));
        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) && !(($scheme === 'https' && $parts['port'] === 443) || ($scheme === 'http' && $parts['port'] === 80)) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : $path;
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        $auth = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '';

        return $scheme . '://' . $auth . $host . $port . $path . $query;
    }

    public static function hostOf(string $normalizedUrl): string
    {
        $host = parse_url($normalizedUrl, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            throw new InvalidUrlException(\sprintf('URL "%s" has no host.', $normalizedUrl));
        }

        return strtolower($host);
    }
}
