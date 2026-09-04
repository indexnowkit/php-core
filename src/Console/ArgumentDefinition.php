<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

/**
 * One positional argument of a command, framework-neutral.
 */
final readonly class ArgumentDefinition
{
    /**
     * @param bool $required the command refuses to run without it
     * @param bool $array    takes every remaining argument (must be the last one)
     */
    public function __construct(
        public string $name,
        public string $description,
        public bool $required = true,
        public bool $array = false,
    ) {}

    public static function required(string $name, string $description): self
    {
        return new self($name, $description);
    }

    public static function optional(string $name, string $description): self
    {
        return new self($name, $description, false);
    }

    /** Every remaining argument; `$required` means at least one. */
    public static function list(string $name, string $description, bool $required = true): self
    {
        return new self($name, $description, $required, true);
    }
}
