<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckItem;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Config;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CheckerTest extends TestCase
{
    public function testLiveProbeUsesTheGivenPageOnlyWhenItBelongsToTheHost(): void
    {
        $t = new FakeTransport();
        $t->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $config = Factory::config(['environment' => 'dev']);
        $checker = new Checker($config, StaticKeyProvider::fromConfig($config), $t);

        $checker->run(liveProbe: true, probeUrl: 'https://www.example.com/blog/hello');
        self::assertSame(['https://www.example.com/blog/hello'], $t->posts[0]['body']['urlList']);

        $checker->run(liveProbe: true, probeUrl: 'https://other.example.net/page');
        self::assertSame(['https://www.example.com/'], $t->posts[1]['body']['urlList'], 'a page of another host falls back to the root');
    }

    public function testExtraChecksRunAfterTheBuiltInOnesAndNeverThrow(): void
    {
        $t = new FakeTransport();
        $t->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $config = Factory::config(['environment' => 'dev']);
        $ok = new class implements \IndexNowKit\Check\CheckInterface {
            public function check(\IndexNowKit\Check\CheckReport $report): void
            {
                $report->ok('cdn: key file purged');
            }
        };
        $broken = new class implements \IndexNowKit\Check\CheckInterface {
            public function check(\IndexNowKit\Check\CheckReport $report): void
            {
                throw new RuntimeException('tenant table unreachable');
            }
        };

        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t, [$ok, $broken]))->run();
        $messages = array_map(static fn(CheckItem $i): string => $i->level->value . ' ' . $i->message, $report->items());

        self::assertContains('ok cdn: key file purged', $messages);
        self::assertStringContainsString('error ' . $broken::class . ' failed: tenant table unreachable', implode("\n", $messages));
        self::assertTrue($report->hasErrors());
    }

    public function testProductionWithoutStrictHostsIsFlagged(): void
    {
        $t = new FakeTransport();
        $t->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $prod = Factory::config(['environment' => 'prod']);
        $messages = array_map(static fn(CheckItem $i): string => $i->level->value . ' ' . $i->message, (new Checker($prod, StaticKeyProvider::fromConfig($prod), $t))->run()->items());
        self::assertStringContainsString('warning strict_hosts is off', implode("\n", $messages));

        $dev = Factory::config(['environment' => 'dev']);
        $messages = array_map(static fn(CheckItem $i): string => $i->message, (new Checker($dev, StaticKeyProvider::fromConfig($dev), $t))->run()->items());
        self::assertStringNotContainsString('strict_hosts is off', implode("\n", $messages), 'no nag outside production');
    }

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

        $warnings = implode("\n", array_column(array_filter($report->items(), static fn(CheckItem $i): bool => $i->level === CheckLevel::Warning), 'message'));
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

    public function testKeyLocationOnAnotherHostIsAnErrorAndIsNeverFetched(): void
    {
        // Config refuses such a setup itself; a custom provider can still produce it, so Checker must not follow it.
        $config = Factory::config();
        $keys = new StaticKeyProvider(Factory::KEY, [], 'http://169.254.169.254/latest/meta-data/');
        $t = (new FakeTransport())->onGet('http://169.254.169.254/latest/meta-data/', new Response(200, Factory::KEY));
        $report = (new Checker($config, $keys, $t))->run();

        self::assertTrue($report->hasErrors());
        self::assertStringContainsString('is on another host', implode("\n", array_column($report->items(), 'message')));
        self::assertSame([], $t->gets, 'no request to a foreign host');
    }

    public function testConfigRejectsKeyLocationOnAnotherHost(): void
    {
        $this->expectException(\IndexNowKit\Exception\ConfigurationException::class);
        Config::fromArray(['key' => Factory::KEY, 'base_url' => 'https://www.example.com', 'key_location' => 'https://other.example.com/key.txt']);
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

        $matching = array_filter($report->items(), static fn(CheckItem $i): bool => $i->level->value === $expectedLevel && str_contains($i->message, 'api'));
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
