<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

use IndexNowKit\Client;
use IndexNowKit\Config;
use IndexNowKit\Engine;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\ResultStatus;
use IndexNowKit\Url\UrlNormalizer;
use Psr\Log\NullLogger;

/**
 * "indexnow check": validates config, fetches the key file over HTTP, performs a dry-run POST.
 * Answers the "why does it not work" question before the first real submission.
 */
final class Checker
{
    public function __construct(
        private readonly Config $config,
        private readonly KeyProviderInterface $keys,
        private readonly TransportInterface $transport,
    ) {}

    public function run(bool $liveProbe = false): CheckReport
    {
        $report = new CheckReport();
        $config = $this->config;

        if (!$config->enabled) {
            $report->warning('IndexNow is disabled (enabled: false). Nothing will be submitted.');
        }
        if ($config->dryRun) {
            $report->warning('dry_run is on: requests are logged, not sent.');
        }
        if ($config->baseUrl === null) {
            $report->warning('base_url is not set: relative URLs and CLI/worker submissions cannot be resolved. Set INDEXNOW_BASE_URL.');
        } else {
            $report->ok(\sprintf('base_url: %s', $config->baseUrl));
        }
        $report->ok(\sprintf('engines: %s', implode(', ', array_map(Engine::labelFor(...), $config->endpoints))));
        $report->ok(\sprintf('dispatch: %s, debounce: %ds, batch: %d', $config->dispatch, $config->debouncePerUrl, $config->batchMaxUrls));

        $hosts = $this->hostsToCheck();
        if ($hosts === []) {
            $report->error('No host to check: set base_url or a hosts map.');

            return $report;
        }

        foreach ($hosts as $host) {
            $key = $this->keys->keyFor($host);
            if ($key === null) {
                $report->error(\sprintf('%s: no key configured.', $host));
                continue;
            }
            if (!KeyValidator::isValid($key)) {
                $report->error(\sprintf('%s: key %s is invalid (8-128 chars, [A-Za-z0-9-]).', $host, KeyValidator::mask($key)));
                continue;
            }
            $keyUrl = $this->keys->keyLocationFor($host) ?? \sprintf('https://%s/%s.txt', $host, $key);
            try {
                $response = $this->transport->get($keyUrl);
                if ($response->status !== 200) {
                    $report->error(\sprintf('%s: GET %s returned HTTP %d. Search engines will answer 403 until the key file is served.', $host, self::maskUrl($keyUrl, $key), $response->status));
                } elseif (trim($response->body) !== $key) {
                    $report->error(\sprintf('%s: key file body does not match the configured key (got %d bytes).', $host, \strlen($response->body)));
                } else {
                    $report->ok(\sprintf('%s: key file OK (%s)', $host, self::maskUrl($keyUrl, $key)));
                }
            } catch (TransportException $e) {
                $report->error(\sprintf('%s: cannot fetch key file: %s', $host, $e->getMessage()));
            }

            if ($liveProbe && $config->enabled) {
                $client = new Client($this->transport, $this->keys, $config->withDryRun(false), new NullLogger());
                $probeUrl = (new UrlNormalizer('https://' . $host))->normalize('/');
                foreach ($config->endpoints as $endpoint) {
                    $result = $client->submitBatch($endpoint, $host, $key, [$probeUrl]);
                    match ($result->status) {
                        ResultStatus::Ok => $report->ok(\sprintf('%s: %s accepted probe (200)', $host, $result->engine)),
                        ResultStatus::Pending => $report->warning(\sprintf('%s: %s answered 202, key verification pending. Retry check later.', $host, $result->engine)),
                        default => $report->error(\sprintf('%s: %s answered %s: %s', $host, $result->engine, (string) $result->httpCode, (string) $result->error)),
                    };
                }
            }
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    private function hostsToCheck(): array
    {
        $hosts = array_keys($this->config->hosts);
        if ($this->config->baseUrl !== null) {
            $hosts[] = UrlNormalizer::hostOf((new UrlNormalizer())->normalize($this->config->baseUrl));
        }

        return array_values(array_unique(array_map('strtolower', $hosts)));
    }

    private static function maskUrl(string $url, string $key): string
    {
        return str_replace($key, KeyValidator::mask($key), $url);
    }
}
