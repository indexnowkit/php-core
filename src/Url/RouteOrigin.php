<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What every `Url\RouteUrlResolverInterface` implementation decides the same way, in one place: which locales a
 * rule expands to, which origin a pinned host generates on, how an origin replaces the one the framework produced,
 * and the two exceptions. The adapters stay `final` and independent — this is a helper they call, not a class they
 * extend — so a framework's router bridge is only the framework's part.
 */
final class RouteOrigin
{
    private function __construct() {}

    /**
     * The locales a rule generates for: the rule's own list as it is (empty = the current locale), `'all'` = the
     * configured list, and when that list is empty the current locale with one warning per process — the rule
     * silently collapsing to a single URL is the one thing a multi-locale site must hear about.
     *
     * @param list<string>|string $locales    the rule's `locales`
     * @param list<string>        $configured the adapter's list (`router.locales`, `framework.enabled_locales`)
     * @param string              $option     the option the warning names
     * @param bool|null           $warned     the adapter's per-process flag, set to true by the first warning; null = warn every time
     *
     * @return list<string|null> null = the current locale
     */
    public static function expand(array|string $locales, array $configured, ?LoggerInterface $logger = null, string $option = 'router.locales', ?bool &$warned = null): array
    {
        if (\is_array($locales)) {
            return $locales === [] ? [null] : $locales;
        }
        if ($locales !== 'all') {
            return [null];
        }
        if ($configured !== []) {
            return $configured;
        }
        if ($logger !== null && $warned !== true) {
            $warned = true;
            $logger->warning(\sprintf('indexnow: a rule asks for locales: \'all\' but "%s" is empty; one URL in the current locale is generated instead of one per locale', $option));
        }

        return [null];
    }

    /** The base URL a rule with `host:` generates on: `hosts.<host>.base_url`, else `https://<host>`. */
    public static function pinnedRoot(Config $config, string $host): string
    {
        return $config->baseUrlFor($host) ?? 'https://' . $host;
    }

    /**
     * $url with the scheme, host and port of $root; path, query and fragment stay. A $root without a scheme and a
     * host leaves $url as it is.
     */
    public static function rebase(string $url, string $root): string
    {
        $target = parse_url($root);
        $source = parse_url($url);
        if (!\is_array($target) || !\is_array($source) || !isset($target['scheme'], $target['host'])) {
            return $url;
        }
        $origin = $target['scheme'] . '://' . $target['host'] . (isset($target['port']) ? ':' . $target['port'] : '');

        return $origin . ($source['path'] ?? '/') . (isset($source['query']) ? '?' . $source['query'] : '') . (isset($source['fragment']) ? '#' . $source['fragment'] : '');
    }

    /** The exception of a router that could not generate the route, with the router's own reason. */
    public static function generationFailed(string $route, Throwable $cause): ConfigurationException
    {
        return new ConfigurationException(\sprintf('Cannot generate route "%s": %s', $route, $cause->getMessage()), 0, $cause);
    }

    /**
     * The exception of a generator that has no request to take the host from and no `base_url` to fall back on.
     *
     * @param string $where where the URL is generated: `a console command`, `a console application`
     */
    public static function noRequestHost(string $where = 'a console command'): ConfigurationException
    {
        return new ConfigurationException(\sprintf('No request to take the host from: set base_url to generate URLs in %s.', $where));
    }
}
