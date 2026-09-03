<?php

declare(strict_types=1);

namespace IndexNowKit;

use Closure;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Exception\InvalidUrlException;
use IndexNowKit\Url\UrlNormalizer;
use IndexNowKit\Url\UrlNormalizerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * One submission: normalize -> dedupe -> debounce -> Client (group, chunk, throttle, POST) -> mark submitted.
 *
 * Ancillary failures (debounce store down, a listener throwing) are logged and never abort delivery.
 * Every outcome, including skipped URLs, is a Result handed to listeners and to the optional PSR-14 dispatcher.
 */
final class Submitter implements SubmitterInterface
{
    /** @var list<callable(Result): void> */
    private array $listeners = [];

    private readonly UrlNormalizerInterface $normalizer;

    /**
     * @param EventDispatcherInterface|null $events receives every Result as an event (PSR-14), in addition to listeners
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly Config $config,
        private readonly DebounceStoreInterface $debounce = new MemoryDebounceStore(),
        private readonly LoggerInterface $logger = new NullLogger(),
        ?UrlNormalizerInterface $normalizer = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->normalizer = $normalizer ?? new UrlNormalizer($config->baseUrl, $config->maxUrlLength);
    }

    public function addListener(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function submit(iterable $urls): array
    {
        [$normalized, $results] = $this->normalize($urls);
        if ($normalized !== []) {
            if (!$this->config->enabled) {
                $this->logger->log($this->config->logLevel('disabled'), 'indexnow: disabled (enabled: false), dropping {count} URL(s)', ['count' => \count($normalized), 'urls' => $this->config->logSample($normalized)]);
                $results = [...$results, ...$this->skipped($normalized, Reason::Disabled)];
                $normalized = [];
            }
        }

        $ttl = $this->config->debouncePerUrl;
        if ($normalized !== [] && $ttl > 0 && !$this->config->dryRun) {
            $fresh = $this->withoutRecent($normalized, $ttl);
            $results = [...$results, ...$this->skipped(array_values(array_diff($normalized, $fresh)), Reason::Debounced)];
            $normalized = $fresh;
        }
        if ($normalized !== []) {
            $results = [...$results, ...$this->client->submitAll($normalized)];
        }
        foreach ($results as $result) {
            $this->notify($result);
        }

        if ($ttl > 0) {
            $sent = Result::urlsWhere($results, static fn(Result $r): bool => $r->isSuccess());
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
        return $this->normalize($urls)[0];
    }

    /**
     * @param iterable<string> $urls
     *
     * @return array{0: list<string>, 1: list<Result>} normalized URLs and one skipped Result per invalid URL
     */
    private function normalize(iterable $urls): array
    {
        $seen = [];
        $invalid = [];
        foreach ($urls as $url) {
            try {
                $seen[$this->normalizer->normalize($url)] = true;
            } catch (InvalidUrlException $e) {
                $this->logger->log($this->config->logLevel('invalid_url'), 'indexnow: dropping URL: {error}', ['error' => $e->getMessage()]);
                $invalid[] = Result::skipped('', [$url], Reason::InvalidUrl, $e->getMessage());
            }
        }

        return [array_keys($seen), $invalid];
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
        $this->logger->log($this->config->logLevel('debounced'), 'indexnow: debounced {count} URL(s) submitted within the last {ttl}s', ['count' => \count($recent), 'ttl' => $ttl, 'urls' => $this->config->logSample($recent)]);
        $skip = array_fill_keys($recent, true);

        return array_values(array_filter($urls, static fn(string $url): bool => !isset($skip[$url])));
    }

    /**
     * One Skipped result per host, so callers can tell "nothing sent" reasons apart.
     *
     * @param list<string> $urls
     *
     * @return list<Result>
     */
    private function skipped(array $urls, Reason $reason): array
    {
        $byHost = [];
        foreach ($urls as $url) {
            $byHost[$this->normalizer->hostOf($url)][] = $url;
        }
        $results = [];
        foreach ($byHost as $host => $hostUrls) {
            $results[] = Result::skipped($host, $hostUrls, $reason);
        }

        return $results;
    }

    private static function describe(callable $listener): string
    {
        if (\is_array($listener)) {
            return (\is_object($listener[0]) ? $listener[0]::class : $listener[0]) . '::' . $listener[1];
        }
        if (\is_string($listener)) {
            return $listener;
        }

        return $listener instanceof Closure ? 'closure' : get_debug_type($listener);
    }

    private function notify(Result $result): void
    {
        try {
            $this->events?->dispatch($result);
        } catch (Throwable $e) {
            $this->logger->error('indexnow: result event listener failed: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
        foreach ($this->listeners as $listener) {
            try {
                $listener($result);
            } catch (Throwable $e) {
                $this->logger->error('indexnow: result listener {listener} failed: {error}', ['listener' => self::describe($listener), 'error' => $e->getMessage(), 'exception' => $e]);
            }
        }
    }
}
