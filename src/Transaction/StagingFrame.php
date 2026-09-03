<?php

declare(strict_types=1);

namespace IndexNowKit\Transaction;

/**
 * One transaction level of {@see TransactionStaging}: the transaction itself (name null) or a savepoint.
 *
 * @internal
 */
final class StagingFrame
{
    /** @var array<string, true> */
    public array $urls = [];

    public function __construct(public readonly ?string $name) {}
}
