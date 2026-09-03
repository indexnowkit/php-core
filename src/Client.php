<?php

declare(strict_types=1);

namespace IndexNowKit;

use IndexNowKit\Exception\InvalidArgumentException;
use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Throttle\NullThrottle;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Url\UrlNormalizer;
use IndexNowKit\Url\UrlNormalizerInterface;
use JsonException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Protocol client: groups already-normalized URLs by host, chunks them, throttles and POSTs one batch
 * per endpoint. Never throws on HTTP status codes or network errors, only on programming errors.
 */
final class Client implements ClientInterface
{
    /** After this many consecutive 403s for a host the log escalates once to critical. */
    /** Default of `logging.forbidden_escalation`; the effective value is {@see Config::$forbiddenEscalation}. */
    public const FORBIDDEN_ESCALATION = Config::DEFAULT_FORBIDDEN_ESCALATION;

    private const LOG_BODY_LIMIT = 300;

    /** @var array<string, int> host => consecutive 403 count */
    private array $forbidden = [];

    private readonly UrlNormalizerInterface $normalizer;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly KeyProviderInterface $keys,
        private readonly Config $config,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ThrottleInterface $throttle = new NullThrottle(),
        ?UrlNormalizerInterface $normalizer = null,
    ) {
        $this->normalizer = $normalizer ?? new UrlNormalizer($config->baseUrl, $config->maxUrlLength);
    }

    /**
     * @param list<string> $normalizedUrls already normalized absolute URLs
     *
     * @return list<Result>
     */
    public function submitAll(array $normalizedUrls): array
    {
        $results = [];
        foreach ($this->groupByHost($normalizedUrls) as $host => $urls) {
            $key = $this->keys->keyFor($host);
            if ($key === null) {
                $this->logger->log($this->config->logLevel('no_key'), 'indexnow: skipping {count} URL(s) for unmanaged host {host}: no key configured (add it to "hosts" or set base_url)', ['count' => \count($urls), 'host' => $host, 'urls' => $this->config->logSample($urls)]);
                $results[] = Result::skipped($host, $urls, Reason::NoKey, \sprintf('No IndexNow key configured for host "%s".', $host));
                continue;
            }
            foreach (array_chunk($urls, max(1, $this->config->batchMaxUrls)) as $chunk) {
                foreach ($this->config->endpointsFor($host) as $endpoint) {
                    $results[] = $this->submitBatch($endpoint, $host, $key, $chunk);
                }
            }
        }

        return $results;
    }

    /**
     * One POST. Throttled unless dry-run.
     *
     * @param list<string> $urls all belonging to $host, count <= batch.max_urls
     *
     * @throws InvalidArgumentException on an empty list
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
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('indexnow: cannot encode {count} URL(s) for {host} as JSON: {error}', ['count' => \count($urls), 'host' => $host, 'error' => $e->getMessage()]);

            return Result::failed($engine, $host, $urls, Reason::Unexpected, 'Cannot encode URL list as JSON: ' . $e->getMessage(), endpoint: $endpoint);
        }

        if ($this->config->dryRun) {
            $this->logger->log($this->config->logLevel('dry_run'), 'indexnow: dry-run POST {endpoint} {body}', ['endpoint' => $endpoint, 'body' => self::maskKey($json, $key)]);

            return Result::skipped($host, $urls, Reason::DryRun, engine: $engine, endpoint: $endpoint);
        }

        try {
            $this->throttle->acquire();
        } catch (Throwable $e) {
            // A throttle must never block delivery; a distributed one that lost its backend proceeds without limiting.
            $this->logger->error('indexnow: throttle failed, sending without rate limiting: {error}', ['error' => $e->getMessage(), 'exception' => $e]);
        }
        try {
            $response = $this->transport->post($endpoint, $json, ['User-Agent' => $this->config->userAgent()]);
        } catch (TransportException $e) {
            $this->logger->log($this->config->logLevel('transport'), 'indexnow: {engine} transport error for {host}: {error}', ['engine' => $engine, 'host' => $host, 'error' => self::maskKey($e->getMessage(), $key)]);

            return Result::failed($engine, $host, $urls, Reason::Transport, self::maskKey($e->getMessage(), $key), retryable: true, endpoint: $endpoint);
        } catch (Throwable $e) {
            // A misbehaving PSR-18 client must not take the request down, nor put $key into a logged stack trace.
            $this->logger->error('indexnow: {engine} HTTP client failure for {host}: {error}', ['engine' => $engine, 'host' => $host, 'error' => self::maskKey($e->getMessage(), $key), 'class' => $e::class]);

            return Result::failed($engine, $host, $urls, Reason::Unexpected, self::maskKey(\sprintf('%s: %s', $e::class, $e->getMessage()), $key), retryable: true, endpoint: $endpoint);
        }

        return $this->interpret($endpoint, $engine, $host, $urls, $response->status, $response->body, $response->retryAfter, $key);
    }

    /**
     * @param list<string> $urls
     */
    private function interpret(string $endpoint, string $engine, string $host, array $urls, int $status, string $body, ?int $retryAfter, string $key): Result
    {
        $ctx = ['engine' => $engine, 'host' => $host, 'count' => \count($urls), 'status' => $status, 'body' => self::maskKey(substr($body, 0, self::LOG_BODY_LIMIT), $key)];
        $failed = fn(Reason $reason, ?string $error = null, bool $retryable = false, ?int $after = null): Result => Result::failed($engine, $host, $urls, $reason, $error, $status, $retryable, $after, $endpoint);

        if ($status !== 403) {
            unset($this->forbidden[$host]);
        }

        return match (true) {
            $status === 200 => $this->log($this->config->logLevel('ok'), 'indexnow: {engine} accepted {count} URL(s) for {host}', $ctx, Result::ok($engine, $host, $urls, 200, $endpoint)),
            $status === 202 => $this->log($this->config->logLevel('pending'), 'indexnow: {engine} accepted {count} URL(s) for {host}, key verification pending (202)', $ctx, Result::ok($engine, $host, $urls, 202, $endpoint)),
            $status === 400 => $this->log($this->config->logLevel('invalid_request'), 'indexnow: {engine} rejected the request as malformed (400): {body}', $ctx, $failed(Reason::InvalidRequest)),
            $status === 403 => $this->forbidden($host, $key, $ctx, $failed(Reason::InvalidKey)),
            $status === 422 => $this->log($this->config->logLevel('unprocessable'), 'indexnow: {engine} could not process URLs for {host} (422): URLs do not belong to the host or keyLocation is invalid', $ctx, $failed(Reason::Unprocessable)),
            $status === 429 => $this->log($this->config->logLevel('rate_limited'), 'indexnow: {engine} rate limited (429) for {host}, retry after {retry_after}s', $ctx + ['retry_after' => $retryAfter ?? '?'], $failed(Reason::RateLimited, null, true, $retryAfter)),
            $status >= 500 => $this->log($this->config->logLevel('server_error'), 'indexnow: {engine} server error {status} for {host}', $ctx, $failed(Reason::ServerError, \sprintf('Server error (%d)', $status), true, $retryAfter)),
            default => $this->log($this->config->logLevel('unexpected'), 'indexnow: {engine} unexpected status {status} for {host}: {body}', $ctx, $failed(Reason::Unexpected, \sprintf('Unexpected status (%d)', $status))),
        };
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function forbidden(string $host, string $key, array $ctx, Result $result): Result
    {
        $count = $this->forbidden[$host] = ($this->forbidden[$host] ?? 0) + 1;
        $ctx += ['key' => KeyValidator::mask($key), 'consecutive' => $count];
        $message = 'indexnow: {engine} rejected the key for {host} (403). Check that https://{host}/{key}.txt is reachable and contains the key (run the check command of your adapter, e.g. indexnow:check).';
        $level = match (true) {
            $count === $this->config->forbiddenEscalation => 'critical',
            $count > $this->config->forbiddenEscalation => 'warning',
            default => 'error',
        };

        return $this->log($level, $count === $this->config->forbiddenEscalation ? $message . ' {consecutive} consecutive failures: submissions for this host are not being indexed.' : $message, $ctx, $result);
    }

    /**
     * @param array<string, mixed> $ctx
     */
    private function log(string $level, string $message, array $ctx, Result $result): Result
    {
        $this->logger->log($level, $message, $ctx);

        return $result;
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, list<string>>
     */
    private function groupByHost(array $urls): array
    {
        $groups = [];
        foreach ($urls as $url) {
            $groups[$this->normalizer->hostOf($url)][] = $url;
        }

        return $groups;
    }

    private static function maskKey(string $text, string $key): string
    {
        return str_replace($key, KeyValidator::mask($key), $text);
    }
}
