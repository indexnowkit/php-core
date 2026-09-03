<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Url\UrlNormalizer;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Pure protocol client: groups URLs by host, chunks, POSTs to every configured endpoint.
 * Never throws on HTTP status codes; only on programming errors.
 */
final class Client
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly KeyProviderInterface $keys,
        private readonly Config $config,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param list<string> $normalizedUrls already normalized absolute URLs
     * @return list<Result>
     */
    public function submitAll(array $normalizedUrls): array
    {
        $results = [];
        foreach (self::groupByHost($normalizedUrls) as $host => $urls) {
            $key = $this->keys->keyFor($host);
            if ($key === null) {
                $this->logger->warning('indexnow: skipping {count} URL(s) for unmanaged host {host}', ['count' => \count($urls), 'host' => $host]);
                continue;
            }
            foreach (array_chunk($urls, max(1, $this->config->batchMaxUrls)) as $chunk) {
                foreach ($this->config->endpoints as $endpoint) {
                    $results[] = $this->submitBatch($endpoint, $host, $key, $chunk);
                }
            }
        }

        return $results;
    }

    /**
     * @param list<string> $urls all belonging to $host, count <= batch.max_urls
     */
    public function submitBatch(string $endpoint, string $host, string $key, array $urls): Result
    {
        if ($urls === []) {
            throw new InvalidArgumentException('Cannot submit an empty URL list.');
        }
        $engine = Engine::labelFor($endpoint);
        $payload = ['host' => $host, 'key' => $key];
        $keyLocation = $this->keys->keyLocationFor($host);
        if ($keyLocation !== null) {
            $payload['keyLocation'] = $keyLocation;
        }
        $payload['urlList'] = $urls;
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($this->config->dryRun) {
            $this->logger->info('indexnow: dry-run POST {endpoint} {body}', ['endpoint' => $endpoint, 'body' => self::maskKey($json, $key)]);

            return new Result($engine, $host, $urls, ResultStatus::Skipped);
        }

        try {
            $response = $this->transport->post($endpoint, $json, ['User-Agent' => $this->config->userAgent()]);
        } catch (TransportException $e) {
            $this->logger->warning('indexnow: {engine} transport error for {host}: {error}', ['engine' => $engine, 'host' => $host, 'error' => $e->getMessage()]);

            return new Result($engine, $host, $urls, ResultStatus::Failed, null, $e->getMessage(), retryable: true);
        }

        return $this->interpret($engine, $host, $urls, $response->status, $response->body, $response->retryAfter, $key);
    }

    /**
     * @param list<string> $urls
     */
    private function interpret(string $engine, string $host, array $urls, int $status, string $body, ?int $retryAfter, string $key): Result
    {
        $ctx = ['engine' => $engine, 'host' => $host, 'count' => \count($urls), 'status' => $status, 'body' => mb_substr($body, 0, 300)];

        switch (true) {
            case $status === 200:
                $this->logger->debug('indexnow: {engine} accepted {count} URL(s) for {host}', $ctx);

                return new Result($engine, $host, $urls, ResultStatus::Ok, $status);
            case $status === 202:
                $this->logger->info('indexnow: {engine} accepted {count} URL(s) for {host}, key verification pending (202)', $ctx);

                return new Result($engine, $host, $urls, ResultStatus::Pending, $status);
            case $status === 400:
                $this->logger->error('indexnow: {engine} rejected request as malformed (400): {body}', $ctx);

                return new Result($engine, $host, $urls, ResultStatus::Failed, $status, 'Invalid request format (400)');
            case $status === 403:
                $this->logger->error('indexnow: {engine} rejected key for {host} (403). Check that https://{host}/{key}.txt is reachable and contains the key.', $ctx + ['key' => KeyValidator::mask($key)]);

                return new Result($engine, $host, $urls, ResultStatus::Failed, $status, 'Invalid key (403): key file not found or does not match');
            case $status === 422:
                $this->logger->warning('indexnow: {engine} could not process URLs for {host} (422): URLs do not belong to host or keyLocation invalid', $ctx);

                return new Result($engine, $host, $urls, ResultStatus::Failed, $status, 'Unprocessable URLs (422)');
            case $status === 429:
                $this->logger->warning('indexnow: {engine} rate limited (429) for {host}', $ctx);

                return new Result($engine, $host, $urls, ResultStatus::Failed, $status, 'Rate limited (429)', retryable: true, retryAfter: $retryAfter);
            case $status >= 500:
                $this->logger->warning('indexnow: {engine} server error {status} for {host}', $ctx);

                return new Result($engine, $host, $urls, ResultStatus::Failed, $status, \sprintf('Server error (%d)', $status), retryable: true, retryAfter: $retryAfter);
            default:
                $this->logger->error('indexnow: {engine} unexpected status {status} for {host}: {body}', $ctx);

                return new Result($engine, $host, $urls, ResultStatus::Failed, $status, \sprintf('Unexpected status (%d)', $status));
        }
    }

    /**
     * @param list<string> $urls
     * @return array<string, list<string>>
     */
    private static function groupByHost(array $urls): array
    {
        $groups = [];
        foreach ($urls as $url) {
            $groups[UrlNormalizer::hostOf($url)][] = $url;
        }

        return $groups;
    }

    private static function maskKey(string $json, string $key): string
    {
        return str_replace($key, KeyValidator::mask($key), $json);
    }
}
