<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

use Closure;
use IndexNowKit\Adapter\OptionalPackage;

/**
 * The `--sample` / `--sample-class` line(s) of `check`, in front of the optional `indexnowkit/verify`: with the
 * package the samples go to its `Check\SampleCheck` (built at check time through $factory, with the options of the
 * running command); without the package a sample is an error naming the install line, and no sample is the plain
 * "not installed" line of {@see OptionalPackage} with " — pre-flight checks off". This class must load without the
 * package, which is why it lives in the core and not in verify; every adapter used to carry a copy of it.
 */
final class SampleGateCheck implements CheckInterface
{
    /** The code of every line this check writes: the one of the package predicate (`verify.installed`). */
    public const CODE = 'verify.installed';

    /**
     * @param (Closure(list<string>, list<string>): CheckInterface)|null $factory builds the package's `SampleCheck` over the
     *                                                                        `--sample` URLs and `--sample-class` specs; null without the package
     * @param string|null                                                 $missing the `check` line without the package ({@see OptionalPackage::checkLine()})
     * @param CheckLevel|null                                             $level   its level ({@see OptionalPackage::checkLevel()})
     */
    public function __construct(private readonly SampleOptions $options, private readonly ?Closure $factory, private readonly ?string $missing = null, private readonly ?CheckLevel $level = null) {}

    /** With the package: the samples go to the check $factory builds. */
    public static function withPackage(SampleOptions $options, Closure $factory): self
    {
        return new self($options, $factory);
    }

    /**
     * Without the package: the line and the level of the predicate over the configured `verify` block.
     *
     * @param array<string, mixed> $block    the `verify` block of the merged configuration
     * @param array<string, mixed> $defaults the block as the adapter's shipped configuration file has it
     */
    public static function withoutPackage(SampleOptions $options, OptionalPackage $package, array $block = [], array $defaults = []): self
    {
        return new self($options, null, $package->checkLine($block, $defaults), $package->checkLevel($block, $defaults));
    }

    public function check(CheckReport $report): void
    {
        if ($this->factory !== null) {
            ($this->factory)($this->options->urls, $this->options->classes)->check($report);

            return;
        }
        if (!$this->options->isEmpty()) {
            $report->error('check --sample needs indexnowkit/verify (composer require indexnowkit/verify)', self::CODE);

            return;
        }
        $line = ($this->missing ?? 'verify: not installed (composer require indexnowkit/verify)') . ' — pre-flight checks off';
        if ($this->level === CheckLevel::Warning) {
            $report->warning($line, self::CODE);
        } else {
            $report->ok($line, self::CODE);
        }
    }
}
