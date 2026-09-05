<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

/**
 * Canonical form on top of another normalizer: the inner one makes the URL absolute and well-formed, this one removes
 * what an engine should not be told about (tracking parameters that external traffic sources append and routing
 * never generates), fixes the trailing slash when the site has a policy, and optionally sorts the query so the same
 * page is one URL for the debounce store and the engines. Applied by `Submitter` before de-duplication and debounce.
 *
 * Built by {@see UrlNormalizerFactory::fromConfig()} from the `normalizer.*` options; `hostOf()` is the inner one's.
 */
final class CanonicalUrlNormalizer implements UrlNormalizerInterface
{
    /**
     * Query parameters removed by `normalizer.strip_tracking_params` (a growing list, like an enum: entries are added
     * in minor versions, never removed; `normalizer.tracking_params` adds yours). `utm_*` is a prefix.
     */
    public const TRACKING_PARAMS = [
        'utm_*', 'gclid', 'dclid', 'wbraid', 'gbraid', 'fbclid', 'msclkid', 'yclid', 'ysclid', '_openstat', 'etext',
        'ttclid', 'twclid', 'igshid', 'mc_cid', 'mc_eid', 'mkt_tok', '_hsenc', '_hsmi', '_ga',
    ];

    public const TRAILING_SLASH_KEEP = 'keep';
    public const TRAILING_SLASH_ADD = 'add';
    public const TRAILING_SLASH_STRIP = 'strip';

    /** @var list<string> lower-cased exact names */
    private readonly array $exact;
    /** @var list<string> lower-cased prefixes (from `name*`) */
    private readonly array $prefixes;

    /**
     * @param bool         $stripTrackingParams remove {@see TRACKING_PARAMS} and $trackingParams from the query
     * @param list<string> $trackingParams      more names to remove (`name` or `prefix*`), case-insensitive
     * @param string       $trailingSlash       `keep` (default), `add` (a path without an extension ends with `/`) or `strip` (no trailing `/` except the root)
     * @param bool         $sortQuery           order the query parameters by name (stable)
     */
    public function __construct(
        private readonly UrlNormalizerInterface $inner,
        private readonly bool $stripTrackingParams = true,
        array $trackingParams = [],
        private readonly string $trailingSlash = self::TRAILING_SLASH_KEEP,
        private readonly bool $sortQuery = false,
    ) {
        $exact = [];
        $prefixes = [];
        foreach ([...self::TRACKING_PARAMS, ...$trackingParams] as $name) {
            $name = strtolower(trim($name));
            if ($name === '' || $name === '*') {
                continue;
            }
            if (str_ends_with($name, '*')) {
                $prefixes[] = substr($name, 0, -1);
            } else {
                $exact[] = $name;
            }
        }
        $this->exact = array_values(array_unique($exact));
        $this->prefixes = array_values(array_unique($prefixes));
    }

    public function normalize(string $url): string
    {
        $url = $this->inner->normalize($url);
        if (!$this->stripTrackingParams && !$this->sortQuery && $this->trailingSlash === self::TRAILING_SLASH_KEEP) {
            return $url;
        }
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url; // the inner normalizer guarantees a parseable absolute URL; nothing to canonicalize otherwise
        }
        $path = $this->path($parts['path'] ?? '/');
        $query = $this->query($parts['query'] ?? null);

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $path . ($query === null ? '' : '?' . $query);
    }

    public function hostOf(string $normalizedUrl): string
    {
        return $this->inner->hostOf($normalizedUrl);
    }

    /** Whether a query parameter name is one of the tracking parameters. */
    public function isTrackingParam(string $name): bool
    {
        $name = strtolower(rawurldecode($name));
        if (\in_array($name, $this->exact, true)) {
            return true;
        }
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function path(string $path): string
    {
        if ($this->trailingSlash === self::TRAILING_SLASH_STRIP) {
            $stripped = rtrim($path, '/');

            return $stripped === '' ? '/' : $stripped;
        }
        if ($this->trailingSlash === self::TRAILING_SLASH_ADD && !str_ends_with($path, '/')) {
            $last = substr($path, (int) strrpos($path, '/') + 1);
            if (!str_contains($last, '.')) {
                return $path . '/';
            }
        }

        return $path;
    }

    /** The query with the tracking parameters removed and, when asked, sorted; null when nothing is left. */
    private function query(?string $query): ?string
    {
        if ($query === null) {
            return null;
        }
        if ($query === '') {
            return $this->stripTrackingParams ? null : '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = explode('=', $pair, 2)[0];
            if ($this->stripTrackingParams && $this->isTrackingParam($name)) {
                continue;
            }
            $pairs[] = [strtolower(rawurldecode($name)), $pair];
        }
        if ($pairs === []) {
            return null;
        }
        if ($this->sortQuery) {
            usort($pairs, static fn(array $a, array $b): int => $a[0] <=> $b[0]); // stable since PHP 8: equal names keep their order
        }

        return implode('&', array_column($pairs, 1));
    }
}
