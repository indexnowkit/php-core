<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

/**
 * One option of a command, framework-neutral: `--dry-run`, `-f|--force`, `--limit=1000`, `--write-env[=FILE]`.
 */
final readonly class OptionDefinition
{
    /** No value: present or absent. */
    public const FLAG = 'flag';
    /** A value is required when the option is given. */
    public const VALUE = 'value';
    /** The option may be given with or without a value (`--write-env`, `--write-env=.env.local`). */
    public const OPTIONAL_VALUE = 'optional';

    /**
     * @param string      $name        kebab-case, as typed after `--`
     * @param string      $mode        one of FLAG, VALUE, OPTIONAL_VALUE
     * @param string|null $shortcut    one letter, as typed after `-`
     * @param string|null $default     printed in the help; for VALUE the value when the option is absent
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $mode = self::FLAG,
        public ?string $shortcut = null,
        public ?string $default = null,
    ) {}

    public static function flag(string $name, string $description, ?string $shortcut = null): self
    {
        return new self($name, $description, self::FLAG, $shortcut);
    }

    public static function value(string $name, string $description, ?string $default = null, ?string $shortcut = null): self
    {
        return new self($name, $description, self::VALUE, $shortcut, $default);
    }

    public static function optionalValue(string $name, string $description): self
    {
        return new self($name, $description, self::OPTIONAL_VALUE);
    }

    /** The name as a property or constructor parameter: `dry-run` is `dryRun`. */
    public function property(): string
    {
        return lcfirst(str_replace('-', '', ucwords($this->name, '-')));
    }
}
