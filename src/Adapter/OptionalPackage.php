<?php

declare(strict_types=1);

namespace IndexNowKit\Adapter;

use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\StaticCheck;

/**
 * An optional package of the family (`indexnowkit/sitemap`, later `verify`, `history`) behind one predicate, so an
 * adapter does not carry its own copy of "is it installed?" plus the three texts that go with it: the install line
 * of the stub command, and the `check` line with or without a configuration block the package would have read.
 * No statics: the override for tests (or for the compile time of a bundle) is a constructor argument, and the
 * adapter decides where it comes from (a bundle parameter, a container binding, a component property).
 */
final class OptionalPackage
{
    /**
     * @param string       $package   Composer name: `indexnowkit/sitemap`
     * @param class-string $marker    a class the package ships; its existence means "installed" (`::class` on an
     *                                absent class is safe, `class_exists()` is not called before {@see installed()})
     * @param string       $feature   the word `check` prints and the name of the configuration block: `sitemap`
     * @param bool|null    $installed override (tests, compile time of a bundle); null = `class_exists($marker)`
     */
    public function __construct(
        public readonly string $package,
        public readonly string $marker,
        public readonly string $feature,
        private readonly ?bool $installed = null,
    ) {}

    public function installed(): bool
    {
        return $this->installed ?? class_exists($this->marker);
    }

    /** What the stub command prints (and the LogicException of a delegate says) without the package. */
    public function notInstalledMessage(): string
    {
        return \sprintf('%s is not installed: composer require %s', $this->package, $this->package);
    }

    /**
     * The `check` line without the package: the plain line when the block is absent or equal to the defaults the
     * adapter ships (its own config file always carries the block), the "ignored" line when the application
     * configured a feature nothing reads.
     *
     * @param array<string, mixed> $block    the feature's block of the merged configuration
     * @param array<string, mixed> $defaults the block as the adapter's shipped configuration file has it
     */
    public function checkLine(array $block, array $defaults = []): string
    {
        return $this->blockIsConfigured($block, $defaults)
            ? \sprintf('%s: not installed, the %s block in the configuration is ignored (composer require %s)', $this->feature, $this->feature, $this->package)
            : \sprintf('%s: not installed (composer require %s)', $this->feature, $this->package);
    }

    /**
     * The level of {@see checkLine()}: ok when nothing is configured (the absence of an optional piece is a fact to
     * print), warning when a configured block is ignored.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $defaults
     */
    public function checkLevel(array $block, array $defaults = []): CheckLevel
    {
        return $this->blockIsConfigured($block, $defaults) ? CheckLevel::Warning : CheckLevel::Ok;
    }

    /**
     * The `check` line as a check to register in the checker's list.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $defaults
     */
    public function check(array $block, array $defaults = []): StaticCheck
    {
        return new StaticCheck($this->checkLevel($block, $defaults), $this->checkLine($block, $defaults));
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $defaults
     */
    private function blockIsConfigured(array $block, array $defaults): bool
    {
        return $block !== [] && self::sorted($block) !== self::sorted($defaults);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function sorted(array $values): array
    {
        ksort($values);
        foreach ($values as $key => $value) {
            if (\is_array($value) && array_is_list($value) === false) {
                /** @var array<string, mixed> $value */
                $values[$key] = self::sorted($value);
            }
        }

        return $values;
    }
}
