<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Exception\InvalidUrlException;

/**
 * Default normalizer: absolute http(s) URL, lower-cased scheme and host, IDN host as punycode,
 * default port and fragment removed, dot-segments resolved, userinfo rejected.
 *
 * Path and query are kept as given (apart from dot-segments and percent-encoded spaces) so the
 * submitted URL matches what the site actually serves.
 */
final class UrlNormalizer implements UrlNormalizerInterface
{
    public const MAX_URL_LENGTH = 2048;
    public const MAX_HOST_LENGTH = 253;
    public const MAX_LABEL_LENGTH = 63;

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(private readonly ?string $baseUrl = null) {}

    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidUrlException('Empty URL.');
        }
        if (\strlen($url) > self::MAX_URL_LENGTH) {
            throw new InvalidUrlException(\sprintf('URL longer than %d bytes.', self::MAX_URL_LENGTH));
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            throw new InvalidUrlException(\sprintf('URL "%s" contains control characters.', self::excerpt($url)));
        }
        if (preg_match('//u', $url) !== 1) {
            throw new InvalidUrlException('URL is not valid UTF-8.');
        }
        $url = str_replace(' ', '%20', $url);
        $url = $this->makeAbsolute($url);

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['scheme'])) {
            throw new InvalidUrlException(\sprintf('Cannot parse URL "%s".', self::excerpt($url)));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidUrlException(\sprintf('URL "%s" contains credentials; only public URLs can be submitted.', self::excerpt($url)));
        }
        $scheme = strtolower($parts['scheme']);
        $host = self::normalizeHost($parts['host']);
        $port = isset($parts['port']) && $parts['port'] !== self::DEFAULT_PORTS[$scheme] ? ':' . $parts['port'] : '';
        $path = self::removeDotSegments($parts['path'] ?? '/');
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $port . $path . $query;
    }

    public function hostOf(string $normalizedUrl): string
    {
        $host = parse_url($normalizedUrl, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            throw new InvalidUrlException(\sprintf('URL "%s" has no host.', self::excerpt($normalizedUrl)));
        }

        return strtolower($host);
    }

    /**
     * @throws InvalidUrlException
     */
    private function makeAbsolute(string $url): string
    {
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $url, $m) === 1) {
            $scheme = strtolower($m[1]);
            if (!isset(self::DEFAULT_PORTS[$scheme])) {
                throw new InvalidUrlException(\sprintf('URL "%s" uses scheme "%s"; only http and https can be submitted.', self::excerpt($url), $scheme));
            }

            return $url;
        }
        if (str_starts_with($url, '//')) {
            $scheme = $this->baseUrl !== null ? (string) parse_url($this->baseUrl, PHP_URL_SCHEME) : 'https';

            return ($scheme === '' ? 'https' : $scheme) . ':' . $url;
        }
        if ($this->baseUrl === null) {
            throw new InvalidUrlException(\sprintf('Relative URL "%s" given but no base_url configured.', self::excerpt($url)));
        }
        if ($url[0] === '/') {
            $origin = parse_url($this->baseUrl);
            if (\is_array($origin) && isset($origin['scheme'], $origin['host'])) {
                return $origin['scheme'] . '://' . $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '') . $url;
            }
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * @throws InvalidUrlException
     */
    private static function normalizeHost(string $host): string
    {
        $host = rtrim(strtolower($host), '.');
        if ($host === '') {
            throw new InvalidUrlException('URL has an empty host.');
        }
        if ($host[0] === '[') {
            if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidUrlException(\sprintf('Invalid IPv6 host "%s".', $host));
            }

            return $host;
        }
        $host = Punycode::encodeHost($host);
        if (\strlen($host) > self::MAX_HOST_LENGTH) {
            throw new InvalidUrlException(\sprintf('Host name longer than %d characters.', self::MAX_HOST_LENGTH));
        }
        foreach (explode('.', $host) as $label) {
            if ($label === '' || \strlen($label) > self::MAX_LABEL_LENGTH || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                throw new InvalidUrlException(\sprintf('Invalid host name "%s".', $host));
            }
        }

        return $host;
    }

    /**
     * RFC 3986 §5.2.4.
     */
    private static function removeDotSegments(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        if (!str_contains($path, '/.')) {
            return $path;
        }
        $output = [];
        foreach (explode('/', $path) as $i => $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (\count($output) > 1) {
                    array_pop($output);
                }
                continue;
            }
            $output[] = $segment;
        }
        $result = implode('/', $output);
        if (str_ends_with($path, '/.') || str_ends_with($path, '/..')) {
            $result .= '/';
        }

        return $result === '' || $result[0] !== '/' ? '/' . ltrim($result, '/') : $result;
    }

    private static function excerpt(string $url): string
    {
        return \strlen($url) > 120 ? substr($url, 0, 117) . '...' : $url;
    }
}
