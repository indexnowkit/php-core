<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Config;
use PHPUnit\Framework\TestCase;

final class ConfigFromEnvTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function fullEnv(): array
    {
        return [
            'INDEXNOW_ENABLED' => 'false',
            'INDEXNOW_KEY' => 'abcdefgh',
            'INDEXNOW_HOSTS' => 'a.com=KEY1abcdef,b.com=KEY2abcdef',
            'INDEXNOW_KEY_LOCATION' => 'https://x.test/k.txt',
            'INDEXNOW_BASE_URL' => 'https://x.test',
            'INDEXNOW_ENGINES' => 'yandex, bing',
            'INDEXNOW_DISPATCH' => 'queue',
            'INDEXNOW_BATCH_MAX_URLS' => '500',
            'INDEXNOW_DEBOUNCE_PER_URL' => '120',
            'INDEXNOW_THROTTLE_PER_MINUTE' => '10',
            'INDEXNOW_HTTP_TIMEOUT' => '5.5',
            'INDEXNOW_USER_AGENT' => 'custom-agent/1.0',
            'INDEXNOW_SERVE_KEY_FILE' => 'false',
            'INDEXNOW_DRY_RUN' => 'true',
            'INDEXNOW_ENV' => 'dev',
        ];
    }

    public function testEveryDocumentedVariableIsRead(): void
    {
        $c = Config::fromEnv(self::fullEnv());

        self::assertFalse($c->enabled);
        self::assertSame('abcdefgh', $c->key);
        self::assertSame(['a.com' => 'KEY1abcdef', 'b.com' => 'KEY2abcdef'], $c->hosts);
        self::assertSame('https://x.test/k.txt', $c->keyLocation);
        self::assertSame('https://x.test', $c->baseUrl);
        self::assertSame(['yandex', 'bing'], $c->engines);
        self::assertSame('queue', $c->dispatch);
        self::assertSame(500, $c->batchMaxUrls);
        self::assertSame(120, $c->debouncePerUrl);
        self::assertSame(10, $c->throttleMaxRequestsPerMinute);
        self::assertSame(5.5, $c->httpTimeout);
        self::assertSame('custom-agent/1.0', $c->userAgent);
        self::assertFalse($c->serveKeyFile);
        // dry_run is explicit true regardless of the ENV dev/prod safety net
        self::assertTrue($c->dryRun);
    }

    public function testAppEnvIsUsedAsFallbackWhenIndexnowEnvIsAbsent(): void
    {
        $c = Config::fromEnv(['APP_ENV' => 'dev']);

        self::assertTrue($c->dryRun, 'no key, non-production APP_ENV -> dry_run instead of an exception');
    }

    public function testIndexnowEnvTakesPrecedenceOverAppEnv(): void
    {
        $c = Config::fromEnv(['INDEXNOW_ENV' => 'production', 'INDEXNOW_KEY' => 'abcdefgh', 'APP_ENV' => 'dev']);

        self::assertFalse($c->dryRun);
    }

    public function testCustomPrefixIsHonoured(): void
    {
        $c = Config::fromEnv(['MYPFX_KEY' => 'abcdefgh', 'MYPFX_BASE_URL' => 'https://y.test'], 'MYPFX_');

        self::assertSame('abcdefgh', $c->key);
        self::assertSame('https://y.test', $c->baseUrl);
    }

    public function testHostsEntryWithoutEqualsSignThrows(): void
    {
        $this->expectException(\IndexNowKit\Exception\ConfigurationException::class);
        Config::fromEnv(['INDEXNOW_KEY' => 'abcdefgh', 'INDEXNOW_HOSTS' => 'not-a-pair']);
    }

    public function testEmptyStringValuesAreTreatedAsAbsent(): void
    {
        $c = Config::fromEnv(['INDEXNOW_KEY' => 'abcdefgh', 'INDEXNOW_BASE_URL' => '']);

        self::assertNull($c->baseUrl);
    }

    public function testDefaultsWhenNoEnvironmentVariablesAreSet(): void
    {
        $c = Config::fromEnv(['INDEXNOW_KEY' => 'abcdefgh']);

        self::assertTrue($c->enabled);
        self::assertSame(['https://api.indexnow.org/indexnow'], $c->endpoints);
        self::assertSame('sync', $c->dispatch);
        self::assertFalse($c->dryRun);
    }
}
