<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

use IndexNowKit\Client;
use IndexNowKit\Config;
use IndexNowKit\Engine;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\ResultStatus;
use IndexNowKit\Url\UrlNormalizer;
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
            }
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
                $hosts[] = (new UrlNormalizer())->hostOf((new UrlNormalizer())->normalize($this->config->baseUrl));
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
