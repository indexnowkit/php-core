<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Support;

use IndexNowKit\Client;
use IndexNowKit\Testing\ArrayLogger;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Config;
use IndexNowKit\Debounce\DebounceStoreInterface;
use IndexNowKit\Debounce\MemoryDebounceStore;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Submitter;
use IndexNowKit\Throttle\NullThrottle;
use IndexNowKit\Throttle\ThrottleInterface;

final class Factory
{
    public const KEY = 'abcdef1234567890abcdef1234567890';

    /**
     * @param array<string, mixed> $overrides
     */
    public static function config(array $overrides = []): Config
    {
        return Config::fromArray($overrides + ['key' => self::KEY, 'base_url' => 'https://www.example.com', 'debounce' => ['per_url' => 0]]);
    }

    public static function submitter(FakeTransport $transport, ?Config $config = null, ?ArrayLogger $logger = null, ?DebounceStoreInterface $debounce = null, ?ThrottleInterface $throttle = null): Submitter
    {
        $config ??= self::config();
        $logger ??= new ArrayLogger();
        $keys = StaticKeyProvider::fromConfig($config);

        return new Submitter(new Client($transport, $keys, $config, $logger, $throttle ?? new NullThrottle()), $config, $debounce ?? new MemoryDebounceStore(), $logger);
    }
}
