<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidConfigProvider(): iterable
    {
        yield 'invalid host name in hosts map' => [['key' => 'abcdefgh', 'hosts' => ['bad host!' => 'abcdefgh']]];
        yield 'non-string host key in hosts map' => [['key' => 'abcdefgh', 'hosts' => [123 => 'abcdefgh']]];
        yield 'invalid key in hosts map' => [['key' => 'abcdefgh', 'hosts' => ['h.example.com' => 'short']]];
        yield 'hosts entry object missing key' => [['key' => 'abcdefgh', 'hosts' => ['h.example.com' => ['key_location' => 'https://h.example.com/k.txt']]]];
        yield 'hosts entry key_location relative' => [['key' => 'abcdefgh', 'hosts' => ['h.example.com' => ['key' => 'abcdefgh12', 'key_location' => '/k.txt']]]];
        yield 'hosts entry key_location no path' => [['key' => 'abcdefgh', 'hosts' => ['h.example.com' => ['key' => 'abcdefgh12', 'key_location' => 'https://h.example.com/']]]];
        yield 'top-level key_location relative' => [['key' => 'abcdefgh', 'key_location' => '/k.txt']];
        yield 'top-level key_location no path' => [['key' => 'abcdefgh', 'key_location' => 'https://h.example.com/']];
        yield 'base_url with credentials' => [['key' => 'abcdefgh', 'base_url' => 'https://user:pass@h.example.com/']];
        yield 'batch max_urls zero' => [['key' => 'abcdefgh', 'batch' => ['max_urls' => 0]]];
        yield 'batch max_urls over protocol max' => [['key' => 'abcdefgh', 'batch' => ['max_urls' => 10001]]];
        yield 'negative debounce' => [['key' => 'abcdefgh', 'debounce' => ['per_url' => -1]]];
        yield 'negative throttle' => [['key' => 'abcdefgh', 'throttle' => ['max_requests_per_minute' => -1]]];
        yield 'http timeout zero' => [['key' => 'abcdefgh', 'http' => ['timeout' => 0]]];
        yield 'empty engines list' => [['key' => 'abcdefgh', 'engines' => []]];
        yield 'user_agent with newline' => [['key' => 'abcdefgh', 'http' => ['user_agent' => "a\nb"]]];
        yield 'batch max_urls non-numeric string' => [['key' => 'abcdefgh', 'batch' => ['max_urls' => 'abc']]];
        yield 'batch max_urls fractional string' => [['key' => 'abcdefgh', 'batch' => ['max_urls' => '10.5']]];
        yield 'debounce non-numeric string' => [['key' => 'abcdefgh', 'debounce' => ['per_url' => 'abc']]];
        yield 'throttle non-numeric string' => [['key' => 'abcdefgh', 'throttle' => ['max_requests_per_minute' => 'abc']]];
        yield 'http timeout non-numeric string' => [['key' => 'abcdefgh', 'http' => ['timeout' => 'abc']]];
        yield 'environment prod without key' => [['environment' => 'prod']];
        yield 'negative key_file.cache_max_age' => [['key' => 'abcdefgh', 'key_file' => ['cache_max_age' => -1]]];
        yield 'non-numeric key_file.cache_max_age' => [['key' => 'abcdefgh', 'key_file' => ['cache_max_age' => 'soon']]];
        yield 'serve_key_file not a scalar' => [['key' => 'abcdefgh', 'serve_key_file' => ['yes']]];
        yield 'environment production without key' => [['environment' => 'PRODUCTION']];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('invalidConfigProvider')]
    public function testRejectsInvalidConfig(array $data): void
    {
        $this->expectException(ConfigurationException::class);
        Config::fromArray($data);
    }

    public function testEnvironmentDevWithoutKeyEnablesDryRunInstead(): void
    {
        $config = Config::fromArray(['environment' => 'dev']);

        self::assertTrue($config->dryRun);
        self::assertTrue($config->enabled);
    }

    public function testEnvironmentProductionWithKeyDoesNotForceDryRun(): void
    {
        $config = Config::fromArray(['environment' => 'production', 'key' => 'abcdefgh']);

        self::assertFalse($config->dryRun);
    }

    public function testHostsEntryWithKeyAndKeyLocationIsAccepted(): void
    {
        $config = Config::fromArray(['key' => 'abcdefgh', 'hosts' => ['h.example.com' => ['key' => 'abcdefgh12', 'key_location' => 'https://h.example.com/k.txt']]]);

        self::assertSame('abcdefgh12', $config->hosts['h.example.com']);
        self::assertSame('https://h.example.com/k.txt', $config->keyLocations['h.example.com']);
    }
}
