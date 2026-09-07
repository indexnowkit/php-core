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
     * Stages the URLs of one change. With a $key (the subject: `class#id`), a later change of the same subject in the
     * same scope merges into the earlier one: the URLs are joined (the old URL of a renamed page stays announced) and
     * the verifier is replaced by the latest, since the verifiers run once, at the end, against the row as the last
     * change left it — two `save()` of one record in one transaction would otherwise discard the first change, whose
     * expected values the second overwrote.
     *
     * @param callable(): bool $verifier true when the change is visible in the database after the transaction
     * @param list<string>     $urls     URLs the change produced
     * @param string           $subject  class#id for log lines
     * @param string|null      $key      identity of the subject within the scope; null = never merged
     */
    public function stage(object $scope, callable $verifier, array $urls, string $subject = '', ?string $key = null): void
    {
        if ($urls === []) {
            return;
        }
        $list = $this->pending[$scope] ?? [];
        if ($key !== null) {
            foreach ($list as $i => $change) {
                if ($change->key === $key) {
                    $list[$i] = new PendingChange($verifier, array_values(array_unique([...$change->urls, ...$urls])), $subject, $key);
                    $this->pending[$scope] = $list;

                    return;
                }
            }
        }
        $list[] = new PendingChange($verifier, array_values(array_unique($urls)), $subject, $key);
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
     * Whether a re-read row still carries the values a change wrote. The row comes back raw from the driver (a
     * string for an integer or a boolean, `'19.90'` for a DECIMAL, a zone suffix on a `timestamptz`, the database's
     * own spelling of a JSON document), the application holds typed values; only the columns whose written value has
     * one unambiguous text form are compared — integers, strings, booleans, backed enums, null — the others (floats,
     * dates, arrays, objects) are skipped like a column the row does not carry. A row with nothing left to compare
     * counts as matching: the question is whether the change landed, and the row is there.
     *
     * For an insert pass no expected values at all: an insert that reached the commit landed by definition, and the
     * row's presence is the whole answer. For a delete ask for `null`.
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
            if (!\array_key_exists($column, $row) || !self::comparable($value)) {
                continue;
            }
            if (self::normalize($row[$column]) !== self::normalize($value)) {
                return false;
            }
        }

        return true;
    }

    /** Whether a written value has one text form every driver agrees on. */
    private static function comparable(mixed $value): bool
    {
        return $value === null || \is_int($value) || \is_string($value) || \is_bool($value) || $value instanceof BackedEnum;
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
