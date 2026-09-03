<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Exception\InvalidUrlException;

/**
 * Canonical form of a URL before dedup, debounce and submission. Replace it to strip tracking
 * parameters, enforce a trailing-slash policy or map hosts.
 */
interface UrlNormalizerInterface
{
    /**
     * @param string $url absolute, protocol-relative or base_url-relative
     *
     * @return string absolute http(s) URL
     *
     * @throws InvalidUrlException when the URL cannot be submitted (wrong scheme, no host, no base_url for a relative path...)
     */
    public function normalize(string $url): string;

    /**
     * Lower-cased host of an already normalized URL.
     *
     * @throws InvalidUrlException
     */
    public function hostOf(string $normalizedUrl): string;
}
