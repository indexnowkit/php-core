<?php

declare(strict_types=1);

namespace IndexNowKit\Adapter;

use Closure;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Builds the runtime Config of a framework adapter from its raw configuration array: the adapter's defaults
 * merged under the raw values, `Config::fromArray()`, the `dispatch` mode resolved and checked against what the
 * adapter can deliver, the adapter's own post-check. {@see build()} throws (check commands, tests, container
 * compilation); {@see load()} never does (runtime: a broken value is logged once at critical and IndexNow runs
 * disabled until it is fixed, so nothing throws from a save hook or a terminate listener).
 *
 * An adapter is one declaration:
 *
 *   new ConfigFactory(ownedOptions: ['queue.connection', ...], dispatchModes: ['queue', 'sync', 'none'],
 *       autoDispatch: fn(): string => $queueExists ? 'queue' : 'sync', defaults: ['dispatch' => 'queue'],
 *       checkCommand: 'php artisan indexnow:check');
 */
final class ConfigFactory
{
    /** Blocks whose keys are merged one level deep with the adapter's defaults (a raw `debounce: {per_url: 5}` keeps the default `debounce.store`). */
    private const KNOWN_BLOCKS = ['http', 'debounce', 'key_file', 'throttle', 'retry', 'batch', 'logging'];

    /** @var list<string> */
    private readonly array $blocks;

    /**
     * @param list<string>                     $ownedOptions  dotted keys the adapter (and its feature packages) accepts on top of Config::OPTIONS
     * @param list<string>                     $dispatchModes the `dispatch` values the adapter can deliver besides `auto`; [0] is the default
     * @param (Closure(): string)|null         $autoDispatch  the mode `dispatch: auto` resolves to; null = "auto is not supported here"
     * @param list<string>                     $needBaseUrl   modes that need `base_url` (a worker has no request to take the host from)
     * @param array<string, scalar|array<string, scalar|null>|null> $defaults
     *        the adapter's values under the core defaults: top-level scalars and blocks of scalars
     *        (`['dispatch' => 'queue', 'debounce' => ['store' => 'cache']]`). Lists (`engines`, `hosts`,
     *        `production_environments`) are refused: merging them with the raw values would be ambiguous.
     * @param (Closure(Config): ?string)|null  $validate      the adapter's post-check; a string is the message of the ConfigurationException
     * @param string                           $checkCommand  how the check command is invoked in this framework, printed in the critical log line
     * @param list<string>                     $ignoreBlocks  top-level blocks {@see unknownOptions()} skips entirely: the block of an optional
     *        package that is not installed (`['sitemap']` without indexnowkit/sitemap), so a configuration written
     *        for the package does not warn once the package is gone. Block names, not dotted keys.
     *
     * @throws LogicException on a list in $defaults, an unknown default dispatch mode or a dotted key in $ignoreBlocks
     */
    public function __construct(
        private readonly array $ownedOptions = [],
        private readonly array $dispatchModes = ['sync', 'none'],
        private readonly ?Closure $autoDispatch = null,
        private readonly array $needBaseUrl = ['queue', 'messenger'],
        private readonly array $defaults = [],
        private readonly ?Closure $validate = null,
        private readonly string $checkCommand = 'indexnow:check',
        private readonly array $ignoreBlocks = [],
    ) {
        if ($dispatchModes === []) {
            throw new LogicException('ConfigFactory: $dispatchModes must name at least one mode.');
        }
        foreach ($ignoreBlocks as $block) {
            if (str_contains($block, '.')) {
                throw new LogicException(\sprintf('ConfigFactory: $ignoreBlocks names top-level blocks, got the key "%s". Owned keys belong to $ownedOptions.', $block));
            }
        }
        foreach ($defaults as $key => $value) {
            if (\is_array($value) && array_is_list($value)) {
                throw new LogicException(\sprintf('ConfigFactory: a list is not allowed in $defaults ("%s"): merging it with the raw configuration is ambiguous. Leave list options to the raw configuration.', $key));
            }
            if (\is_array($value)) {
                foreach ($value as $sub => $subValue) {
                    if (\is_array($subValue)) {
                        throw new LogicException(\sprintf('ConfigFactory: $defaults["%s"]["%s"] must be a scalar or null; defaults are one level deep.', $key, $sub));
                    }
                }
            }
        }
        $blocks = self::KNOWN_BLOCKS;
        foreach ($ownedOptions as $option) {
            $dot = strpos($option, '.');
            if ($dot !== false) {
                $blocks[] = substr($option, 0, $dot);
            }
        }
        $this->blocks = array_values(array_unique($blocks));
    }

    /**
     * Strict path: the Config, or a ConfigurationException naming the option.
     *
     * @param array<string, mixed> $raw the adapter's configuration array (its own blocks included)
     *
     * @throws ConfigurationException
     */
    public function build(array $raw, ?string $environment): Config
    {
        $merged = $this->merge($raw);
        $explicit = $merged['environment'] ?? null;
        if (!\is_string($explicit) || $explicit === '') {
            // The framework's environment name unless the configuration names one itself (an unset env var is "").
            $merged['environment'] = $environment;
        }
        $dispatch = $merged['dispatch'] ?? null;
        if ($dispatch === 'auto') {
            if ($this->autoDispatch === null) {
                throw new ConfigurationException(\sprintf('"dispatch" is "auto", which this adapter does not support; use one of: %s.', implode(', ', $this->dispatchModes)));
            }
            $merged['dispatch'] = ($this->autoDispatch)();
        }
        $config = Config::fromArray($merged);
        if (!\in_array($config->dispatch, $this->dispatchModes, true)) {
            throw new ConfigurationException(\sprintf('"dispatch" must be one of %s, got "%s".', implode(', ', $this->autoDispatch === null ? $this->dispatchModes : ['auto', ...$this->dispatchModes]), $config->dispatch));
        }
        if ($config->baseUrl === null && \in_array($config->dispatch, $this->needBaseUrl, true)) {
            throw new ConfigurationException(\sprintf('"dispatch" is "%s" but "base_url" is not set: a worker has no request to take the host from.', $config->dispatch));
        }
        if ($this->validate !== null) {
            $problem = ($this->validate)($config);
            if ($problem !== null) {
                throw new ConfigurationException($problem);
            }
        }

        return $config;
    }

    /**
     * Safe path for the runtime: unknown keys are a warning, an invalid value is a critical log line and a
     * disabled Config. Never throws.
     *
     * @param array<string, mixed> $raw
     */
    public function load(array $raw, ?string $environment, LoggerInterface $logger): Config
    {
        $unknown = $this->unknownOptions($raw);
        if ($unknown !== []) {
            $logger->warning('indexnow: unknown option(s) in the indexnow configuration: {options}', ['options' => implode(', ', $unknown)]);
        }
        try {
            return $this->build($raw, $environment);
        } catch (ConfigurationException $e) {
            $logger->critical('indexnow: invalid configuration, IndexNow is disabled until it is fixed: {error} (run "{check}")', ['error' => $e->getMessage(), 'check' => $this->checkCommand, 'exception' => $e]);

            return self::disabled($environment);
        }
    }

    /**
     * Dotted keys of $raw that neither Config::OPTIONS nor the adapter's owned options know; the ignored blocks are
     * left out entirely.
     *
     * @param array<string, mixed> $raw
     *
     * @return list<string>
     */
    public function unknownOptions(array $raw): array
    {
        if ($this->ignoreBlocks !== []) {
            $raw = array_diff_key($raw, array_flip($this->ignoreBlocks));
        }

        return Config::unknownOptions($raw, $this->ownedOptions);
    }

    /**
     * The Config IndexNow runs with while the configuration is broken: nothing is sent, nothing throws.
     */
    public static function disabled(?string $environment): Config
    {
        return new Config(enabled: false, dryRun: true, environment: $environment);
    }

    /**
     * Defaults under raw: a top-level raw key replaces the default entirely, except for the known blocks (and the
     * blocks the owned options name), which are merged key by key. No array_replace_recursive: it would turn the
     * list options into dictionaries.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function merge(array $raw): array
    {
        $merged = $this->defaults;
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if (\is_array($value) && !array_is_list($value) && \in_array($key, $this->blocks, true) && \is_array($merged[$key] ?? null)) {
                /** @var array<string, mixed> $block */
                $block = $merged[$key];
                foreach ($value as $sub => $subValue) {
                    $block[(string) $sub] = $subValue;
                }
                $merged[$key] = $block;
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged;
    }
}
