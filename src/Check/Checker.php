<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

use IndexNowKit\Client;
use IndexNowKit\Config;
use IndexNowKit\Engine;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\Response;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\ResultStatus;
use IndexNowKit\Url\UrlNormalizerFactory;
use Psr\Log\NullLogger;
use Throwable;

/**
 * "indexnow check": validates configuration, fetches every key file over HTTP and optionally sends a live
 * probe. Answers "why does it not work" before the first real submission. Never throws.
 *
 * Every line carries a stable code ({@see CheckItem::$code}, listed in docs/check-codes.md); the texts are for humans.
 */
final class Checker implements CheckerInterface
{
    /**
     * @param iterable<CheckInterface> $checks extra checks run after the built-in ones
     */
    public function __construct(
        private readonly Config $config,
        private readonly KeyProviderInterface $keys,
        private readonly TransportInterface $transport,
        private readonly iterable $checks = [],
    ) {}

    /**
     * @param bool        $liveProbe POST the site root to every endpoint (real request, even with dry_run on)
     * @param string|null $onlyHost  check this host only (multi-domain setups)
     * @param string|null $probeUrl  page to probe with (default: https://<host>/; give a real page when the root redirects)
     */
    public function run(bool $liveProbe = false, ?string $onlyHost = null, ?string $probeUrl = null): CheckReport
    {
        $report = new CheckReport();
        $config = $this->config;

        if (!$config->enabled) {
            $report->warning('IndexNow is disabled (enabled: false). Nothing will be submitted.', 'config.enabled');
        }
        if ($config->dryRun) {
            if ($config->isProduction()) {
                $report->error(\sprintf('dry_run is on in a production environment (%s): nothing is sent to the engines.', (string) $config->environment), 'config.dry_run');
            } else {
                $report->warning('dry_run is on: requests are logged, not sent.', 'config.dry_run');
            }
        }
        $this->checkEnvironment($report);
        if ($config->strictHosts) {
            $report->ok('strict_hosts: URLs of hosts outside base_url/hosts are skipped', 'config.strict_hosts');
        } elseif ($config->hosts !== [] && $config->key !== null) {
            $report->warning('hosts map without strict_hosts: URLs of hosts not listed are still sent under the default key. Set strict_hosts: true unless that is intended.', 'config.strict_hosts');
        } elseif ($config->isProduction() && $config->key !== null && $config->baseUrl !== null) {
            $report->warning(\sprintf('strict_hosts is off: URLs of any host this application is reached under (a staging copy, an internal hostname) are submitted under the %s key. Set strict_hosts: true unless that is intended.', (string) $config->baseHost()), 'config.strict_hosts');
        }
        if ($config->baseUrl === null) {
            $report->warning('base_url is not set: relative URLs and CLI/worker submissions cannot be resolved. Set INDEXNOW_BASE_URL.', 'config.base_url');
        } else {
            $report->ok(\sprintf('base_url: %s', $config->baseUrl), 'config.base_url');
        }
        $report->ok(\sprintf('engines: %s', implode(', ', array_map(Engine::labelFor(...), $config->endpoints))), 'config.engines');
        $report->ok(\sprintf('dispatch: %s, debounce: %ds, batch: %d, throttle: %d/min, timeout: %ss', $config->dispatch, $config->debouncePerUrl, $config->batchMaxUrls, $config->throttleMaxRequestsPerMinute, $config->httpTimeout), 'config.delivery');
        if ($config->httpClient !== null) {
            $report->warning(\sprintf('http.client: the key files below are fetched through your client "%s"; if it follows redirects, a 30x to a catch-all page looks like a 200 here. Verify with curl -I as well.', $config->httpClient), 'http.client');
        }

        $hosts = $this->hostsToCheck();
        if ($onlyHost !== null) {
            $hosts = [strtolower($onlyHost)];
        }
        if ($hosts === []) {
            $report->error('No host to check: set base_url or a hosts map.', 'config.hosts');

            return $report;
        }
        foreach ($hosts as $host) {
            $this->checkHost($host, $liveProbe, $report, $probeUrl);
        }
        $this->extraChecks($report);

        return $report;
    }

    /**
     * The `environment` line. Outside production with a key and dry_run off, real requests leave a staging
     * copy: an error when dry_run was merely left unset, a warning when the configuration says `dry_run: false`
     * on purpose (a preview environment that submits). Without an environment name there is nothing to judge.
     */
    private function checkEnvironment(CheckReport $report): void
    {
        $config = $this->config;
        if ($config->environment === null) {
            return;
        }
        if ($config->isProduction()) {
            $report->ok(\sprintf('environment: %s', $config->environment), 'environment.name');

            return;
        }
        $submits = $config->enabled && !$config->dryRun && ($config->key !== null || $config->hosts !== []);
        if ($submits) {
            $under = $config->key !== null ? 'key ' . KeyValidator::mask($config->key) : \sprintf('the keys of %d host(s)', \count($config->hosts));
            if ($config->dryRunExplicit) {
                $report->warning(\sprintf('environment "%s" is not in production_environments but dry_run is explicitly false, assuming this environment submits on purpose: changes are sent to search engines under %s.', $config->environment, $under), 'environment.non_production_submits');
            } else {
                $report->error(\sprintf('environment "%s" is not in production_environments but dry_run is off: changes WILL be sent to search engines under %s. Set INDEXNOW_DRY_RUN=1 or INDEXNOW_ENABLED=0 outside production, or set dry_run: false explicitly if this environment submits on purpose.', $config->environment, $under), 'environment.non_production_submits');
            }
        }
        $line = \sprintf('environment: %s (not in production_environments: %s)', $config->environment, implode(', ', $config->productionEnvironments));
        $submits ? $report->warning($line, 'environment.name') : $report->ok($line, 'environment.name');
    }

    private function extraChecks(CheckReport $report): void
    {
        foreach ($this->checks as $check) {
            try {
                $check->check($report);
            } catch (Throwable $e) {
                $report->error(\sprintf('%s failed: %s', $check::class, $e->getMessage()), 'check.failed');
            }
        }
    }

    private function checkHost(string $host, bool $liveProbe, CheckReport $report, ?string $probeUrl): void
    {
        $key = $this->keys->keyFor($host);
        if ($key === null) {
            $report->error(\sprintf('%s: no key configured.', $host), 'key.missing', $host);

            return;
        }
        if (!KeyValidator::isValid($key)) {
            $report->error(\sprintf('%s: key %s is invalid (%d-%d chars, [A-Za-z0-9-]).', $host, KeyValidator::mask($key), KeyValidator::MIN_LENGTH, KeyValidator::MAX_LENGTH), 'key.invalid', $host);

            return;
        }
        $keyUrl = $this->keys->keyLocationFor($host) ?? \sprintf('https://%s/%s.txt', $host, $key);
        $keyUrlHost = parse_url($keyUrl, PHP_URL_HOST);
        if (!\is_string($keyUrlHost) || strtolower($keyUrlHost) !== strtolower($host)) {
            $report->error(\sprintf('%s: key_location %s is on another host; engines answer 422 unless the key file is served from the submitted host.', $host, self::maskUrl($keyUrl, $key)), 'key_file.location', $host);

            return;
        }
        if (!$this->config->serveKeyFile && $this->keys->keyLocationFor($host) === null) {
            $report->warning(\sprintf('%s: serve_key_file is off and no key_location is set; make sure %s is served by your web server.', $host, self::maskUrl($keyUrl, $key)), 'key_file.served_externally', $host);
        }
        try {
            $response = $this->transport->get($keyUrl);
            if ($response->status !== 200) {
                $report->error(\sprintf('%s: GET %s returned HTTP %d. Search engines will answer 403 until the key file is served with 200 (no redirects).', $host, self::maskUrl($keyUrl, $key), $response->status), 'key_file.status', $host);
            } elseif (trim($response->body) !== $key) {
                $report->error(\sprintf('%s: key file body does not match the configured key (got %d bytes starting with "%s"); a 200 answer with HTML usually means a catch-all route matched before the key file route.', $host, \strlen($response->body), self::maskUrl(self::excerpt($response->body), $key)), 'key_file.body', $host);
            } else {
                $report->ok(\sprintf('%s: key file OK (%s)', $host, self::maskUrl($keyUrl, $key)), 'key_file.status', $host);
                $this->checkKeyFileHeaders($host, $key, $response, $report);
            }
            $this->checkRobots($host, $keyUrl, $key, $report);
            $this->checkPreviousKey($host, $keyUrl, $report);
        } catch (TransportException $e) {
            $report->error(\sprintf('%s: cannot fetch key file: %s', $host, self::maskUrl($e->getMessage(), $key)), 'key_file.fetch', $host);
        } catch (ConfigurationException $e) {
            $report->error(\sprintf('%s: no HTTP client to fetch the key file: %s', $host, $e->getMessage()), 'key_file.fetch', $host);

            return;
        }

        if ($liveProbe && $this->config->enabled) {
            $this->probe($host, $key, $report, $probeUrl);
        }
    }

    /**
     * Content-Type and caching of a key file that answered 200 with the right body. A transport that exposes no headers
     * (`Response::$headers` empty) gets one neutral line instead of a verdict.
     */
    private function checkKeyFileHeaders(string $host, string $key, Response $response, CheckReport $report): void
    {
        if ($response->headers === []) {
            $report->ok(\sprintf('%s: Content-Type unknown (this transport does not expose headers)', $host), 'key_file.content_type', $host);

            return;
        }
        $type = $response->contentType();
        if ($type === null) {
            $report->warning(\sprintf('%s: key file is served without a Content-Type header; serve it as text/plain, which every engine accepts.', $host), 'key_file.content_type', $host);
        } elseif ($type !== 'text/plain') {
            $report->error(\sprintf('%s: key file is served as %s, not text/plain; engines may refuse to verify the key. Fix the Content-Type of the key file response.', $host, $type), 'key_file.content_type', $host);
        } else {
            $report->ok(\sprintf('%s: Content-Type text/plain', $host), 'key_file.content_type', $host);
        }

        $limit = $this->config->keyFileMaxAge;
        $maxAge = $response->cacheMaxAge();
        $age = $response->age();
        if ($age !== null && $age > $limit) {
            $report->warning(\sprintf('%s: the key file came from a cache %ds old (Age), longer than key_file.cache_max_age (%ds): the CDN in front ignores the max-age, a key rotation would serve the old key for as long. Purge the key file on rotation or shorten the CDN rule.', $host, $age, $limit), 'key_file.cache_control', $host);
        } elseif ($maxAge !== null && $maxAge > $limit) {
            $report->warning(\sprintf('%s: key file Cache-Control allows caching for %ds, longer than key_file.cache_max_age (%ds): after a key rotation a CDN may serve the old key for up to %ds and every submission gets 403 meanwhile. Keep it at or below %ds.', $host, $maxAge, $limit, $maxAge, $limit), 'key_file.cache_control', $host);
        } elseif ($maxAge !== null) {
            $report->ok(\sprintf('%s: key file cached for at most %ds (Cache-Control)', $host, $maxAge), 'key_file.cache_control', $host);
        }
    }

    /**
     * robots.txt of the host: a `Disallow` that covers the key file path (for every bot or for the IndexNow engines'
     * bots) keeps the engines from verifying the key. Nothing is printed when robots.txt is absent or unreachable.
     */
    private function checkRobots(string $host, string $keyUrl, string $key, CheckReport $report): void
    {
        $path = parse_url($keyUrl, PHP_URL_PATH);
        $path = \is_string($path) && $path !== '' ? $path : '/' . $key . '.txt';
        try {
            $robots = $this->transport->get(\sprintf('%s://%s/robots.txt', parse_url($keyUrl, PHP_URL_SCHEME) === 'http' ? 'http' : 'https', $host));
        } catch (TransportException) {
            return;
        }
        if ($robots->status !== 200) {
            return;
        }
        $rule = self::robotsDisallows($robots->body, $path);
        if ($rule !== null) {
            $report->warning(\sprintf('%s: robots.txt disallows the key file (%s): engines cannot fetch %s to verify the key. Allow it (Allow: %s) or move the rule.', $host, self::maskUrl($rule, $key), self::maskUrl($path, $key), self::maskUrl($path, $key)), 'key_file.robots', $host);
        } else {
            $report->ok(\sprintf('%s: robots.txt does not block the key file', $host), 'key_file.robots', $host);
        }
    }

    /**
     * `previous_key` (per host, else global): the old key file must still be served while engines that cached the old
     * key catch up; once `check --live` is green the option goes away.
     */
    private function checkPreviousKey(string $host, string $keyUrl, CheckReport $report): void
    {
        $previous = $this->config->previousKeys[$host] ?? $this->config->previousKey;
        if ($previous === null) {
            return;
        }
        $url = \sprintf('%s://%s/%s.txt', parse_url($keyUrl, PHP_URL_SCHEME) === 'http' ? 'http' : 'https', $host, $previous);
        try {
            $response = $this->transport->get($url);
        } catch (TransportException $e) {
            $report->warning(\sprintf('%s: previous_key is set but its key file cannot be fetched (%s): engines that still verify against the old key answer 403 until they pick up the new one.', $host, self::maskUrl($e->getMessage(), $previous)), 'key_file.previous', $host);

            return;
        }
        if ($response->status === 200 && trim($response->body) === $previous) {
            $report->ok(\sprintf('%s: previous key file OK (%s): rotation window open; remove previous_key once check --live is green', $host, self::maskUrl($url, $previous)), 'key_file.previous', $host);
        } else {
            $report->warning(\sprintf('%s: previous_key is set but %s answers HTTP %d%s: engines that still verify against the old key answer 403 until they pick up the new one. Serve the old key file during the rotation, or remove previous_key when it is over.', $host, self::maskUrl($url, $previous), $response->status, $response->status === 200 ? ' with another body' : ''), 'key_file.previous', $host);
        }
    }

    /**
     * The `Disallow` rule of $robots that covers $path for every bot or for an IndexNow engine's bot, null when the
     * path is allowed. Groups by `User-agent`; `*` and `$` in rules as in the robots.txt convention; the longest
     * matching rule wins, `Allow` on a tie.
     */
    public static function robotsDisallows(string $robots, string $path): ?string
    {
        $relevant = false;
        $sawRule = false;
        $disallow = null;
        $disallowLength = -1;
        $allowLength = -1;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $robots)) as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);
            if ($field === 'user-agent') {
                if ($sawRule) {
                    $relevant = false;
                    $sawRule = false;
                }
                $relevant = $relevant || $value === '*' || preg_match('/bing|msnbot|yandex|seznam|naver|yeti|amazon|ia_archiver|archive/i', $value) === 1;

                continue;
            }
            if ($field !== 'disallow' && $field !== 'allow') {
                continue;
            }
            $sawRule = true;
            if (!$relevant || $value === '' || !self::robotsRuleMatches($value, $path)) {
                continue;
            }
            if ($field === 'disallow' && \strlen($value) > $disallowLength) {
                $disallow = $value;
                $disallowLength = \strlen($value);
            } elseif ($field === 'allow' && \strlen($value) > $allowLength) {
                $allowLength = \strlen($value);
            }
        }

        return $disallow !== null && $disallowLength > $allowLength ? 'Disallow: ' . $disallow : null;
    }

    private static function robotsRuleMatches(string $rule, string $path): bool
    {
        $pattern = '#^' . str_replace('\*', '.*', preg_quote(rtrim($rule, '$'), '#')) . (str_ends_with($rule, '$') ? '$' : '') . '#';

        return preg_match($pattern, $path) === 1;
    }

    /**
     * @param string|null $probeUrl a real page of $host (default: its https root, which a redirecting root makes useless)
     */
    private function probe(string $host, string $key, CheckReport $report, ?string $probeUrl): void
    {
        try {
            $config = $this->config->with(dryRun: false);
        } catch (ConfigurationException $e) {
            $report->error(\sprintf('%s: cannot build a live configuration: %s', $host, $e->getMessage()), 'probe.config', $host);

            return;
        }
        $client = new Client($this->transport, $this->keys, $config, new NullLogger());
        $probeUrl = $probeUrl !== null && strcasecmp((string) parse_url($probeUrl, PHP_URL_HOST), $host) === 0 ? $probeUrl : 'https://' . $host . '/';
        foreach ($this->config->endpoints as $endpoint) {
            $result = $client->submitBatch($endpoint, $host, $key, [$probeUrl]);
            match ($result->status) {
                ResultStatus::Ok => $report->ok(\sprintf('%s: %s accepted probe (200)', $host, $result->engine), 'probe.response', $host),
                ResultStatus::Pending => $report->warning(\sprintf('%s: %s answered 202, key verification pending. Retry check later.', $host, $result->engine), 'probe.response', $host),
                default => $report->error(\sprintf('%s: %s answered %s: %s', $host, $result->engine, $result->httpCode !== null ? (string) $result->httpCode : 'no response', (string) $result->error), 'probe.response', $host),
            };
        }
    }

    /**
     * @return list<string>
     */
    private function hostsToCheck(): array
    {
        $hosts = $this->keys->managedHosts();
        if ($this->config->baseUrl !== null) {
            try {
                $normalizer = UrlNormalizerFactory::fromConfig($this->config);
                $hosts[] = $normalizer->hostOf($normalizer->normalize($this->config->baseUrl));
            } catch (InvalidUrlException) {
                $hosts[] = (string) $this->config->baseHost();
            }
        }

        return array_values(array_unique(array_map('strtolower', array_filter($hosts, static fn(string $h): bool => $h !== ''))));
    }

    /** The printable start of a response body, for the mismatch line. */
    private static function excerpt(string $body): string
    {
        $head = (string) preg_replace('/[^\\x20-\\x7e]+/', ' ', substr(ltrim($body), 0, 60));

        return trim($head) . (\strlen($body) > 60 ? '…' : '');
    }

    private static function maskUrl(string $text, string $key): string
    {
        return str_replace($key, KeyValidator::mask($key), $text);
    }
}
