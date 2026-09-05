<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

/**
 * One line of a {@see CheckReport}.
 *
 * `$code` is the stable identifier of the check that produced the line (`key_file.status`, `debounce.store`,
 * `queue.connection`), the same for every level it can print at: the message is for humans and gets improved, the
 * code is API (`docs/check-codes.md`) and is what `check --json` consumers and alert rules match on. `$host` is set
 * on the lines that are about one host (key file, probe), null on the global ones. Null codes come from
 * application checks that do not name one; every check the family ships has a code.
 */
final readonly class CheckItem
{
    public function __construct(public CheckLevel $level, public string $message, public ?string $code = null, public ?string $host = null) {}
}
