<?php

declare(strict_types=1);

namespace IndexNowKit\Transaction;

/**
 * One staged change of {@see VerifyingStaging}: the URLs it produced and how to tell whether it was committed.
 *
 * @internal
 */
final class PendingChange
{
    /** @var callable(): bool */
    public readonly mixed $verifier;

    /**
     * @param callable(): bool $verifier
     * @param list<string>     $urls
     * @param string           $subject class#id for log lines
     * @param string|null      $key     identity of the subject within its scope, for merging ({@see VerifyingStaging::stage()})
     */
    public function __construct(callable $verifier, public readonly array $urls, public readonly string $subject = '', public readonly ?string $key = null)
    {
        $this->verifier = $verifier;
    }
}
