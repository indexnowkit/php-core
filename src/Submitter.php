<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Url\UrlNormalizer;
use IndexNowKit\Url\UrlNormalizerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * One submission: normalize -> dedupe -> debounce -> Client (group, chunk, throttle, POST) -> mark submitted.
 *
 * Ancillary failures (debounce store down, a listener throwing) are logged and never abort delivery.
 */
final class Submitter implements SubmitterInterface
{
    /** @var list<callable(Result): void> */
    private array $listeners = [];

    private readonly UrlNormalizerInterface $normalizer;

    public function __construct(
        private readonly Client $client,
        private readonly Config $config,
        private readonly DebounceStoreInterface $debounce = new MemoryDebounceStore(),
        private readonly LoggerInterface $logger = new NullLogger(),
        ?UrlNormalizerInterface $normalizer = null,
    ) {
        $this->normalizer = $normalizer ?? new UrlNormalizer($config->baseUrl);
    }

    /**
     * Called with every Result (also skipped ones), after the batch was sent. Exceptions are logged and swallowed.
     *
     * @param callable(Result): void $listener
     */
    public function addListener(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function submit(iterable $urls): array
    {
        $normalized = $this->prepare($urls);
        if ($normalized === []) {
            return [];
        }
        if (!$this->config->enabled) {
            $this->logger->debug('indexnow: disabled, dropping {count} URL(s)', ['count' => \count($normalized), 'urls' => $normalized]);

            return [];
        }

        $ttl = $this->config->debouncePerUrl;
        if ($ttl > 0 && !$this->config->dryRun) {
            $normalized = $this->withoutRecent($normalized, $ttl);
            if ($normalized === []) {
                return [];
            }
        }

        $results = $this->client->submitAll($normalized);
        foreach ($results as $result) {
            $this->notify($result);
        }

        if ($ttl > 0) {
            $sent = Result::urlsOf($results, static fn(Result $r): bool => $r->isSuccess());
            if ($sent !== []) {
                try {
                    $this->debounce->markSubmitted($sent, $ttl);
                } catch (Throwable $e) {
                    $this->logger->warning('indexnow: debounce store failed after a successful submission, URLs may be re-sent within {ttl}s: {error}', ['ttl' => $ttl, 'error' => $e->getMessage()]);
                }
            }
        }

        return $results;
    }

    public function prepare(iterable $urls): array
    {
        $seen = [];
        foreach ($urls as $url) {
            try {
                $seen[$this->normalizer->normalize($url)] = true;
            } catch (InvalidUrlException $e) {
                $this->logger->warning('indexnow: dropping URL: {error}', ['error' => $e->getMessage()]);
            }
        }

        return array_keys($seen);
    }

    /**
     * Debounce filter; fails open (submits everything) when the store is unavailable.
     *
     * @param list<string> $urls
     *
     * @return list<string>
     */
    private function withoutRecent(array $urls, int $ttl): array
    {
        try {
            $recent = $this->debounce->filterRecent($urls, $ttl);
        } catch (Throwable $e) {
            $this->logger->warning('indexnow: debounce store unavailable, submitting without de-duplication: {error}', ['error' => $e->getMessage()]);

            return $urls;
        }
        if ($recent === []) {
            return $urls;
        }
        $this->logger->debug('indexnow: debounced {count} URL(s) submitted within the last {ttl}s', ['count' => \count($recent), 'ttl' => $ttl]);
        $skip = array_fill_keys($recent, true);

        return array_values(array_filter($urls, static fn(string $url): bool => !isset($skip[$url])));
    }

    private function notify(Result $result): void
    {
        foreach ($this->listeners as $listener) {
            try {
                $listener($result);
            } catch (Throwable $e) {
                $this->logger->error('indexnow: result listener failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
