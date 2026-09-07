<?php

declare(strict_types=1);

namespace IndexNowKit\Adapter;

use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\StaticCheck;
use IndexNowKit\Exception\ConfigurationException;

/**
 * An optional package of the family (`indexnowkit/sitemap`, later `verify`, `history`) behind one predicate, so an
 * adapter does not carry its own copy of "is it installed?" plus the three texts that go with it: the install line
 * of the stub command, and the `check` line with or without a configuration block the package would have read.
 * No statics: the override for tests (or for the compile time of a bundle) is a constructor argument, and the
 * adapter decides where it comes from (a bundle parameter, a container binding, a component property).
 *
 * The three packages of the family have their names, markers and feature words here ({@see sitemap()},
 * {@see verify()}, {@see history()}), so that an adapter builds the predicate without loading a class of the package
 * it asks about: `Sitemap\Adapter\SitemapServices::package()` lives in `indexnowkit/sitemap` and cannot answer
 * "not installed" (the packages' `*Services::package()` delegate here). The markers are strings, not `::class`
 * constants, so that this file names no class of a package the core does not require. The same for the options a
 * package owns ({@see options()}): the name of the package's `*Services::options()` is a string, called only once
 * {@see installed()} said yes, so an adapter's `ConfigFactory` is one {@see ownedOptions()} and one
 * {@see ignoredBlocks()} over the three predicates instead of three copies of the same two lines.
 */
final class OptionalPackage
{
    /**
     * @param string       $package         Composer name: `indexnowkit/sitemap`
     * @param string       $marker          a class the package ships; its existence means "installed" (`::class` on an
     *                                      absent class is safe, `class_exists()` is not called before {@see installed()})
     * @param string       $feature         the word `check` prints and the name of the configuration block: `sitemap`
     * @param bool|null    $installed       override (tests, compile time of a bundle); null = `class_exists($marker)`
     * @param string|null  $optionsProvider a static method of the package returning the dotted keys of its block
     *                                      (`IndexNowKit\Sitemap\Adapter\SitemapServices::options`), called by
     *                                      {@see options()} only when the package is installed; null = the package owns no options
     */
    public function __construct(
        public readonly string $package,
        public readonly string $marker,
        public readonly string $feature,
        private readonly ?bool $installed = null,
        private readonly ?string $optionsProvider = null,
    ) {}

    /**
     * The predicate for `indexnowkit/sitemap` (the reader, the spool check and the `sitemap` command); null =
     * detect, false = wire as if the package were absent (tests, the compile time of a bundle).
     */
    public static function sitemap(?bool $installed = null): self
    {
        return new self('indexnowkit/sitemap', 'IndexNowKit\\Sitemap\\SitemapReader', 'sitemap', $installed, 'IndexNowKit\\Sitemap\\Adapter\\SitemapServices::options');
    }

    /** The predicate for `indexnowkit/verify` (the pre-flight GET before submission). */
    public static function verify(?bool $installed = null): self
    {
        return new self('indexnowkit/verify', 'IndexNowKit\\Verify\\PageSignals', 'verify', $installed, 'IndexNowKit\\Verify\\Adapter\\VerifyServices::options');
    }

    /** The predicate for `indexnowkit/history` (the submission store and the `history` / `status` commands). */
    public static function history(?bool $installed = null): self
    {
        return new self('indexnowkit/history', 'IndexNowKit\\History\\HistoryConfig', 'history', $installed, 'IndexNowKit\\History\\Adapter\\HistoryServices::options'); // a class, not the interface: installed() asks class_exists()
    }

    public function installed(): bool
    {
        return $this->installed ?? class_exists($this->marker);
    }

    /**
     * The dotted keys of the package's block (`sitemap.url`, …) for the adapter's `ConfigFactory`: the answer of
     * `$optionsProvider` when the package is installed, nothing otherwise — the block is then ignored as a whole
     * ({@see ignoredBlocks()}), and no class of the package is loaded to say so.
     *
     * @return list<string>
     *
     * @throws ConfigurationException when the package is installed but the provider is not callable or returns no list of strings
     */
    public function options(): array
    {
        if ($this->optionsProvider === null || !$this->installed()) {
            return [];
        }
        if (!\is_callable($this->optionsProvider)) {
            throw new ConfigurationException(\sprintf('%s is installed but %s is not callable: the package and the core are out of step, update both.', $this->package, $this->optionsProvider));
        }
        $options = ($this->optionsProvider)();
        if (!\is_array($options)) {
            throw new ConfigurationException(\sprintf('%s() must return the option keys of %s as a list of strings, got %s.', $this->optionsProvider, $this->package, get_debug_type($options)));
        }

        return array_values(array_filter($options, 'is_string'));
    }

    /**
     * The options of every installed package of the list, for the `ownedOptions:` of the adapter's `ConfigFactory`.
     *
     * @param list<self> $packages
     *
     * @return list<string>
     */
    public static function ownedOptions(array $packages): array
    {
        return array_merge([], ...array_map(static fn(self $package): array => $package->options(), $packages));
    }

    /**
     * The feature names of every package of the list that is not installed, for the `ignoreBlocks:` of the adapter's
     * `ConfigFactory`: a block written for a package that is absent is not an unknown option.
     *
     * @param list<self> $packages
     *
     * @return list<string>
     */
    public static function ignoredBlocks(array $packages): array
    {
        $blocks = [];
        foreach ($packages as $package) {
            if (!$package->installed()) {
                $blocks[] = $package->feature;
            }
        }

        return $blocks;
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

    /** The code of the `check` line ({@see Check\CheckItem::$code}): `<feature>.installed`. */
    public function checkCode(): string
    {
        return $this->feature . '.installed';
    }

    /**
     * The `check` line as a check to register in the checker's list.
     *
     * @param array<string, mixed> $block
     * @param array<string, mixed> $defaults
     */
    public function check(array $block, array $defaults = []): StaticCheck
    {
        return new StaticCheck($this->checkLevel($block, $defaults), $this->checkLine($block, $defaults), $this->checkCode());
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
