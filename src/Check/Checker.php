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

/**
 * "indexnow check": validates configuration, fetches every key file over HTTP and optionally sends a live
 * probe. Answers "why does it not work" before the first real submission. Never throws.
 */
final class Checker
{
    public function __construct(
        private readonly Config $config,
        private readonly KeyProviderInterface $keys,
        private readonly TransportInterface $transport,
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
            $report->warning('IndexNow is disabled (enabled: false). Nothing will be submitted.');
        }
        if ($config->dryRun) {
            if ($config->isProduction()) {
                $report->error(\sprintf('dry_run is on in a production environment (%s): nothing is sent to the engines.', (string) $config->environment));
            } else {
                $report->warning('dry_run is on: requests are logged, not sent.');
            }
        }
        if ($config->strictHosts) {
            $report->ok('strict_hosts: URLs of hosts outside base_url/hosts are skipped');
        } elseif ($config->hosts !== [] && $config->key !== null) {
            $report->warning('hosts map without strict_hosts: URLs of hosts not listed are still sent under the default key. Set strict_hosts: true unless that is intended.');
        } elseif ($config->isProduction() && $config->key !== null && $config->baseUrl !== null) {
            $report->warning(\sprintf('strict_hosts is off: URLs of any host this application is reached under (a staging copy, an internal hostname) are submitted under the %s key. Set strict_hosts: true unless that is intended.', (string) $config->baseHost()));
        }
        if ($config->baseUrl === null) {
            $report->warning('base_url is not set: relative URLs and CLI/worker submissions cannot be resolved. Set INDEXNOW_BASE_URL.');
        } else {
            $report->ok(\sprintf('base_url: %s', $config->baseUrl));
        }
        $report->ok(\sprintf('engines: %s', implode(', ', array_map(Engine::labelFor(...), $config->endpoints))));
        $report->ok(\sprintf('dispatch: %s, debounce: %ds, batch: %d, throttle: %d/min, timeout: %ss', $config->dispatch, $config->debouncePerUrl, $config->batchMaxUrls, $config->throttleMaxRequestsPerMinute, $config->httpTimeout));

        $hosts = $this->hostsToCheck();
        if ($onlyHost !== null) {
            $hosts = [strtolower($onlyHost)];
        }
        if ($hosts === []) {
            $report->error('No host to check: set base_url or a hosts map.');

            return $report;
        }
        foreach ($hosts as $host) {
            $this->checkHost($host, $liveProbe, $report, $probeUrl);
        }

        return $report;
    }

    private function checkHost(string $host, bool $liveProbe, CheckReport $report, ?string $probeUrl): void
    {
        $key = $this->keys->keyFor($host);
        if ($key === null) {
            $report->error(\sprintf('%s: no key configured.', $host));

            return;
        }
        if (!KeyValidator::isValid($key)) {
            $report->error(\sprintf('%s: key %s is invalid (%d-%d chars, [A-Za-z0-9-]).', $host, KeyValidator::mask($key), KeyValidator::MIN_LENGTH, KeyValidator::MAX_LENGTH));

            return;
        }
        $keyUrl = $this->keys->keyLocationFor($host) ?? \sprintf('https://%s/%s.txt', $host, $key);
        $keyUrlHost = parse_url($keyUrl, PHP_URL_HOST);
        if (!\is_string($keyUrlHost) || strtolower($keyUrlHost) !== strtolower($host)) {
            $report->error(\sprintf('%s: key_location %s is on another host; engines answer 422 unless the key file is served from the submitted host.', $host, self::maskUrl($keyUrl, $key)));

            return;
        }
        if (!$this->config->serveKeyFile && $this->keys->keyLocationFor($host) === null) {
            $report->warning(\sprintf('%s: serve_key_file is off and no key_location is set; make sure %s is served by your web server.', $host, self::maskUrl($keyUrl, $key)));
        }
        try {
            $response = $this->transport->get($keyUrl);
            if ($response->status !== 200) {
                $report->error(\sprintf('%s: GET %s returned HTTP %d. Search engines will answer 403 until the key file is served with 200 (no redirects).', $host, self::maskUrl($keyUrl, $key), $response->status));
            } elseif (trim($response->body) !== $key) {
                $report->error(\sprintf('%s: key file body does not match the configured key (got %d bytes).', $host, \strlen($response->body)));
            } else {
                $report->ok(\sprintf('%s: key file OK (%s)', $host, self::maskUrl($keyUrl, $key)));
            }
        } catch (TransportException $e) {
            $report->error(\sprintf('%s: cannot fetch key file: %s', $host, self::maskUrl($e->getMessage(), $key)));
        } catch (ConfigurationException $e) {
            $report->error(\sprintf('%s: no HTTP client to fetch the key file: %s', $host, $e->getMessage()));

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
            $report->error(\sprintf('%s: cannot build a live configuration: %s', $host, $e->getMessage()));

            return;
        }
        $client = new Client($this->transport, $this->keys, $config, new NullLogger());
        $probeUrl = $probeUrl !== null && strcasecmp((string) parse_url($probeUrl, PHP_URL_HOST), $host) === 0 ? $probeUrl : 'https://' . $host . '/';
        foreach ($this->config->endpoints as $endpoint) {
            $result = $client->submitBatch($endpoint, $host, $key, [$probeUrl]);
            match ($result->status) {
                ResultStatus::Ok => $report->ok(\sprintf('%s: %s accepted probe (200)', $host, $result->engine)),
                ResultStatus::Pending => $report->warning(\sprintf('%s: %s answered 202, key verification pending. Retry check later.', $host, $result->engine)),
                default => $report->error(\sprintf('%s: %s answered %s: %s', $host, $result->engine, $result->httpCode !== null ? (string) $result->httpCode : 'no response', (string) $result->error)),
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

    private static function maskUrl(string $text, string $key): string
    {
        return str_replace($key, KeyValidator::mask($key), $text);
    }
}
