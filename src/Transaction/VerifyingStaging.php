<?php

declare(strict_types=1);

namespace IndexNowKit\Transaction;

use BackedEnum;
use DateTimeInterface;
use IndexNowKit\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;
use Throwable;
use WeakMap;

/**
 * Commit-safety for data layers that give no signal on COMMIT or ROLLBACK (Yii2 savepoints, Yii3 `yiisoft/db`).
 *
 * URLs resolved while a transaction is open are held back together with a verifier: a closure that re-reads the
 * row by primary key and answers whether the change it announced actually landed (created/updated: the row exists
 * with the new values; deleted: the row is gone). {@see flush()} runs the verifiers once the data layer says the
 * transaction is over (or at the end of the request when it says nothing) and hands over only the URLs whose
 * change survived; a change that did not survive drops every URL it produced, including `via` pages and the old
 * URL of a renamed page, since announcing those would be wrong.
 *
 * One primary-key lookup per staged subject, only for changes made inside an explicit transaction; autocommitted
 * changes never come here. Keyed by a scope object whose identity outlives the transaction (the connection).
 */
final class VerifyingStaging
{
    /** @var WeakMap<object, list<PendingChange>> */
    private WeakMap $pending;

    /**
     * @param int $logUrls URLs listed in the discard log line ({@see Config::$logUrls})
     */
    public function __construct(private readonly LoggerInterface $logger = new NullLogger(), private readonly int $logUrls = Config::DEFAULT_LOG_URLS)
    {
        $this->pending = new WeakMap();
    }

    /**
     * @param callable(): bool $verifier true when the change is visible in the database after the transaction
     * @param list<string>     $urls     URLs the change produced
     */
    public function stage(object $scope, callable $verifier, array $urls, string $subject = ''): void
    {
        if ($urls === []) {
            return;
        }
        $list = $this->pending[$scope] ?? [];
        $list[] = new PendingChange($verifier, array_values(array_unique($urls)), $subject);
        $this->pending[$scope] = $list;
    }

    /**
     * The transaction is over: run the verifiers and return the URLs of the changes that survived.
     *
     * @return list<string>
     */
    public function flush(object $scope): array
    {
        $list = $this->pending[$scope] ?? [];
        unset($this->pending[$scope]);
        $urls = [];
        foreach ($list as $change) {
            try {
                $survived = ($change->verifier)();
            } catch (Throwable $e) {
                // Cannot tell: announcing is the safer default, a stale URL costs one crawl; a lost one costs the update.
                $this->logger->warning('indexnow: cannot verify a staged change of {subject}, submitting anyway: {error}', ['subject' => $change->subject, 'error' => $e->getMessage(), 'exception' => $e]);
                $survived = true;
            }
            if ($survived) {
                foreach ($change->urls as $url) {
                    $urls[$url] = true;
                }
            } else {
                $this->logger->debug('indexnow: discarding {count} staged URL(s) of {subject}, change not committed', ['count' => \count($change->urls), 'subject' => $change->subject, 'urls' => \array_slice($change->urls, 0, $this->logUrls)]);
            }
        }

        return array_keys($urls);
    }

    /**
     * The transaction rolled back: nothing to verify, everything staged is dropped.
     */
    public function discard(object $scope): void
    {
        $list = $this->pending[$scope] ?? [];
        unset($this->pending[$scope]);
        $count = 0;
        $sample = [];
        foreach ($list as $change) {
            $count += \count($change->urls);
            $sample = [...$sample, ...$change->urls];
        }
        if ($count > 0) {
            $this->logger->debug('indexnow: discarding {count} staged URL(s), transaction rolled back', ['count' => $count, 'urls' => \array_slice($sample, 0, $this->logUrls)]);
        }
    }

    public function hasPending(object $scope): bool
    {
        return ($this->pending[$scope] ?? []) !== [];
    }

    public function pendingCount(object $scope): int
    {
        $count = 0;
        foreach ($this->pending[$scope] ?? [] as $change) {
            $count += \count($change->urls);
        }

        return $count;
    }

    /**
     * Whether a re-read row still carries the values a change wrote: loose comparison after casting both sides to
     * string, since drivers return strings for integers and booleans and the application may hold typed values.
     *
     * @param array<string, mixed>|null $row      the row as re-read (null = no row)
     * @param array<string, mixed>      $expected column => value the change wrote
     */
    public static function rowMatches(?array $row, array $expected): bool
    {
        if ($row === null) {
            return false;
        }
        foreach ($expected as $column => $value) {
            if (!\array_key_exists($column, $row)) {
                continue;
            }
            if (self::normalize($row[$column]) !== self::normalize($value)) {
                return false;
            }
        }

        return true;
    }

    private static function normalize(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            \is_bool($value) => $value ? '1' : '0',
            \is_scalar($value) => (string) $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof Stringable => (string) $value,
            default => json_encode($value, JSON_THROW_ON_ERROR),
        };
    }
}
