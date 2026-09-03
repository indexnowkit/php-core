<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Throttle\TokenBucket;
use IndexNowKit\Url\UrlNormalizer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Orchestrates one submission: normalize -> dedupe -> debounce -> throttle -> Client -> mark submitted.
 */
final class Submitter
{
    /** @var list<callable(Result): void> */
    private array $listeners = [];

    private readonly UrlNormalizer $normalizer;
    private readonly TokenBucket $throttle;

    public function __construct(
        private readonly Client $client,
        private readonly Config $config,
        private readonly DebounceStoreInterface $debounce = new MemoryDebounceStore(),
        private readonly LoggerInterface $logger = new NullLogger(),
        ?TokenBucket $throttle = null,
    ) {
        $this->normalizer = new UrlNormalizer($config->baseUrl);
        $this->throttle = $throttle ?? new TokenBucket($config->throttleMaxRequestsPerMinute);
    }

    /**
     * @param callable(Result): void $listener
     */
    public function addListener(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * @param iterable<string> $urls absolute or base_url-relative
     * @return list<Result>
     */
    public function submit(iterable $urls): array
    {
        $normalized = $this->prepare($urls);
        if ($normalized === []) {
            return [];
        }
        if (!$this->config->enabled) {
            $this->logger->debug('indexnow: disabled, dropping {count} URL(s)', ['count' => \count($normalized)]);

            return [];
        }

        $ttl = $this->config->debouncePerUrl;
        if ($ttl > 0 && !$this->config->dryRun) {
            $recent = $this->debounce->filterRecent($normalized, $ttl);
            if ($recent !== []) {
                $this->logger->debug('indexnow: debounced {count} URL(s) submitted within the last {ttl}s', ['count' => \count($recent), 'ttl' => $ttl]);
                $normalized = array_values(array_diff($normalized, $recent));
            }
            if ($normalized === []) {
                return [];
            }
        }

        $results = [];
        foreach (self::groupByHost($normalized) as $hostUrls) {
            foreach (array_chunk($hostUrls, max(1, $this->config->batchMaxUrls)) as $chunk) {
                $this->throttle->acquire();
                foreach ($this->client->submitAll($chunk) as $result) {
                    $results[] = $result;
                    foreach ($this->listeners as $listener) {
                        $listener($result);
                    }
                }
            }
        }

        if ($ttl > 0) {
            $sent = [];
            foreach ($results as $result) {
                if ($result->isSuccess()) {
                    $sent = [...$sent, ...$result->urls];
                }
            }
            if ($sent !== []) {
                $this->debounce->markSubmitted(array_values(array_unique($sent)), $ttl);
            }
        }

        return $results;
    }

    /**
     * Normalize and dedupe, dropping invalid URLs with a warning.
     *
     * @param iterable<string> $urls
     * @return list<string>
     */
    public function prepare(iterable $urls): array
    {
        $seen = [];
        foreach ($urls as $url) {
            try {
                $normalized = $this->normalizer->normalize($url);
            } catch (InvalidUrlException $e) {
                $this->logger->warning('indexnow: dropping URL: {error}', ['error' => $e->getMessage()]);
                continue;
            }
            $seen[$normalized] = true;
        }

        return array_keys($seen);
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
}
