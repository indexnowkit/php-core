<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Client;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\NullDebounceStore;
use IndexNowKit\Http\TransportInterface;
use IndexNowKit\IndexNowKit;
use IndexNowKit\Key\KeyProviderInterface;
use IndexNowKit\Submitter;
use IndexNowKit\SubmitterInterface;
use IndexNowKit\Throttle\ThrottleInterface;
use IndexNowKit\Url\UrlNormalizerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Submitters for console commands: `--force` bypasses the debounce store, `--dry-run` logs instead of sending.
 * Neither touches the application's own Submitter.
 */
final class SubmitterFactory implements SubmitterFactoryInterface
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly KeyProviderInterface $keys,
        private readonly Config $config,
        private readonly DebounceStoreInterface $debounce,
        private readonly ThrottleInterface $throttle,
        private readonly UrlNormalizerInterface $normalizer,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?EventDispatcherInterface $events = null,
    ) {}

    public function create(bool $force, bool $dryRun): SubmitterInterface
    {
        $config = $dryRun ? $this->config->with(dryRun: true) : $this->config;
        $client = new Client($this->transport, $this->keys, $config, $this->logger, $this->throttle, $this->normalizer);

        return new Submitter($client, $config, $force ? new NullDebounceStore() : $this->debounce, $this->logger, $this->normalizer, $this->events);
    }

    /**
     * The submitter a command submits through: the application's own unless `--force` or `--dry-run` asks for a
     * separate one.
     */
    public static function choose(SubmitterFactoryInterface $factory, IndexNowKit $indexNow, bool $force, bool $dryRun): SubmitterInterface
    {
        return $force || $dryRun ? $factory->create($force, $dryRun) : $indexNow->submitter;
    }
}
