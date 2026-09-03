<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

/**
 * Raw command-line input of `submit-<subject>`, before validation: the runner reports bad values itself so every
 * framework prints the same messages.
 */
final class SubmitSubjectsOptions
{
    /**
     * @param string       $class   class argument as typed (FQCN or short name)
     * @param list<string> $ids     identifiers; none = every object of the class up to $limit
     * @param string       $event   created | updated | deleted
     * @param int          $limit   max objects loaded when no ids are given
     * @param bool         $explain show which rule produced which URL and submit nothing
     */
    public function __construct(
        public readonly string $class,
        public readonly array $ids = [],
        public readonly string $event = 'updated',
        public readonly int $limit = 1000,
        public readonly bool $explain = false,
        public readonly bool $force = false,
        public readonly bool $dryRun = false,
        public readonly bool $json = false,
    ) {}
}
