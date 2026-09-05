<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Config;

/**
 * The normalizer of a configuration: `Url\UrlNormalizer` over `base_url` and `max_url_length`, wrapped in
 * `Url\CanonicalUrlNormalizer` when the `normalizer.*` options ask for anything (which they do by default:
 * `normalizer.strip_tracking_params` is on). The one place the core and the adapters build it, so the options work
 * the same in plain PHP and behind every container.
 */
final class UrlNormalizerFactory
{
    private function __construct() {}

    public static function fromConfig(Config $config): UrlNormalizerInterface
    {
        $inner = new UrlNormalizer($config->baseUrl, $config->maxUrlLength);
        if (!$config->normalizerStripTrackingParams && !$config->normalizerSortQuery && $config->normalizerTrailingSlash === CanonicalUrlNormalizer::TRAILING_SLASH_KEEP) {
            return $inner;
        }

        return new CanonicalUrlNormalizer($inner, $config->normalizerStripTrackingParams, $config->normalizerTrackingParams, $config->normalizerTrailingSlash, $config->normalizerSortQuery);
    }
}
