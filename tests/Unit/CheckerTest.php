<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Check\Checker;
use IndexNowKit\Config;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Tests\Support\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckerTest extends TestCase
{
    public function testKeyFileOkAndLiveProbe(): void
    {
        $config = Factory::config();
        $t = (new FakeTransport())->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY . "\n"));
        $t->willRespond(new Response(202));

        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run(liveProbe: true);

        self::assertFalse($report->hasErrors());
        $messages = array_column($report->items(), 'message');
        self::assertStringContainsString('key file OK', implode("\n", $messages));
        self::assertStringContainsString('202', implode("\n", $messages));
        self::assertSame(['https://www.example.com/'], $t->posts[0]['body']['urlList']);
    }

    public function testKeyFileMissingIsError(): void
    {
        $config = Factory::config();
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), new FakeTransport()))->run();

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('HTTP 404', implode("\n", array_column($report->items(), 'message')));
    }

    public function testKeyMismatchIsError(): void
    {
        $config = Factory::config();
        $t = (new FakeTransport())->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, 'other'));
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run();

        self::assertTrue($report->hasErrors());
    }

    public function testDisabledDryRunAndMissingBaseUrlAreWarnings(): void
    {
        $config = Config::fromArray(['dry_run' => true, 'enabled' => false]);
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), new FakeTransport()))->run();

        $warnings = implode("\n", array_column(array_filter($report->items(), static fn(array $i): bool => $i['level'] === 'warning'), 'message'));
        self::assertStringContainsString('disabled', $warnings);
        self::assertStringContainsString('dry_run is on', $warnings);
        self::assertStringContainsString('base_url is not set', $warnings);
    }

    public function testNoHostToCheckIsAnError(): void
    {
        $config = Config::fromArray(['dry_run' => true, 'enabled' => false]);
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), new FakeTransport()))->run();

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('No host to check', implode("\n", array_column($report->items(), 'message')));
    }

    public function testKeyLocationOnAnotherHostIsAnError(): void
    {
        $config = Config::fromArray(['key' => Factory::KEY, 'base_url' => 'https://www.example.com', 'key_location' => 'https://other.example.com/key.txt']);
        $t = (new FakeTransport())->onGet('https://other.example.com/key.txt', new Response(200, Factory::KEY));
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run();

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('is on another host', implode("\n", array_column($report->items(), 'message')));
    }

    public function testTransportFailureDoesNotLeakTheRawKey(): void
    {
        $config = Factory::config();
        $t = (new FakeTransport())->onGet('https://www.example.com/' . Factory::KEY . '.txt', FakeTransport::failing('dns error for ' . Factory::KEY));
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run();

        $messages = implode("\n", array_column($report->items(), 'message'));
        self::assertTrue($report->hasErrors());
        self::assertStringNotContainsString(Factory::KEY, $messages);
    }

    /**
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function liveProbeOutcomeProvider(): iterable
    {
        yield '200 is ok' => [200, 'ok'];
        yield '202 is a warning (pending)' => [202, 'warning'];
        yield '403 is an error' => [403, 'error'];
    }

    #[DataProvider('liveProbeOutcomeProvider')]
    public function testLiveProbeOutcomes(int $status, string $expectedLevel): void
    {
        $config = Factory::config();
        $t = (new FakeTransport())->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $t->willRespond(new Response($status));

        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run(liveProbe: true);

        $matching = array_filter($report->items(), static fn(array $i): bool => $i['level'] === $expectedLevel && str_contains($i['message'], 'api'));
        self::assertNotEmpty($matching, \sprintf('expected a %s-level item mentioning the engine for status %d', $expectedLevel, $status));
    }

    public function testManagedHostsFromProviderAreAllChecked(): void
    {
        $config = Config::fromArray(['hosts' => ['a.example.com' => Factory::KEY, 'b.example.com' => Factory::KEY]]);
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), new FakeTransport()))->run();

        $messages = implode("\n", array_column($report->items(), 'message'));
        self::assertStringContainsString('a.example.com', $messages);
        self::assertStringContainsString('b.example.com', $messages);
    }
}
