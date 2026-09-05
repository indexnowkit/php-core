<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckItem;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Config;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
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
        $t = (new FakeTransport())->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, "<!DOCTYPE html>\n<html><head><title>Home</title></head><body>" . str_repeat('x', 100)));
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run();

        self::assertTrue($report->hasErrors());
        $errors = implode("\n", array_column(array_filter($report->items(), static fn(CheckItem $i): bool => $i->level === CheckLevel::Error), 'message'));
        self::assertStringContainsString('starting with "<!DOCTYPE html> <html><head><title>Home</title></head><body>…"', $errors, 'the body excerpt is printed, control characters collapsed');
        self::assertStringContainsString('a 200 answer with HTML usually means a catch-all route matched before the key file route', $errors);
    }

    #[TestDox('environment: unset says nothing; staging with a key and dry_run unset is an error; explicit dry_run: false a warning; production an ok line')]
    public function testEnvironmentLineHasFourStates(): void
    {
        $t = new FakeTransport();
        $t->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $lines = function (array $overrides) use ($t): array {
            $config = Factory::config($overrides);
            $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run();

            return array_values(array_map(static fn(CheckItem $i): string => $i->level->value . ' ' . $i->message, array_filter($report->items(), static fn(CheckItem $i): bool => str_contains($i->message, 'environment'))));
        };

        self::assertSame([], $lines([]), 'plain PHP without APP_ENV/INDEXNOW_ENV: nothing to judge');

        $staging = $lines(['environment' => 'staging']);
        self::assertCount(2, $staging);
        self::assertSame('error environment "staging" is not in production_environments but dry_run is off: changes WILL be sent to search engines under key ' . KeyValidator::mask(Factory::KEY) . '. Set INDEXNOW_DRY_RUN=1 or INDEXNOW_ENABLED=0 outside production, or set dry_run: false explicitly if this environment submits on purpose.', $staging[0]);
        self::assertSame('warning environment: staging (not in production_environments: prod, production)', $staging[1]);

        $explicit = $lines(['environment' => 'staging', 'dry_run' => false]);
        self::assertCount(2, $explicit);
        self::assertSame('warning environment "staging" is not in production_environments but dry_run is explicitly false, assuming this environment submits on purpose: changes are sent to search engines under key ' . KeyValidator::mask(Factory::KEY) . '.', $explicit[0]);
        self::assertSame('warning environment: staging (not in production_environments: prod, production)', $explicit[1]);

        self::assertSame(['ok environment: prod'], $lines(['environment' => 'prod']));
        self::assertSame(['ok environment: dev (not in production_environments: prod, production)'], $lines(['environment' => 'dev', 'dry_run' => true]), 'dry_run on: nothing leaves, no error');
        self::assertSame(['ok environment: dev (not in production_environments: prod, production)'], $lines(['environment' => 'dev', 'enabled' => false]), 'disabled: nothing leaves');
        self::assertSame($staging, $lines(['environment' => 'staging', 'dry_run' => null]), 'a null dry_run (an unset env var read by a config file) counts as unset');
    }

    public function testDryRunExplicitFollowsTheConfiguration(): void
    {
        self::assertFalse(Factory::config()->dryRunExplicit);
        self::assertFalse(Factory::config(['dry_run' => null])->dryRunExplicit);
        self::assertTrue(Factory::config(['dry_run' => false])->dryRunExplicit);
        self::assertTrue(Factory::config(['dry_run' => true])->dryRunExplicit);
        self::assertTrue(Factory::config()->withDryRun(false)->dryRunExplicit);
        self::assertTrue(Factory::config()->with(dryRun: true)->dryRunExplicit);
        self::assertFalse(Factory::config()->with(engines: ['yandex'])->dryRunExplicit, 'other changes keep the flag');
        self::assertTrue((new Config(key: Factory::KEY))->dryRunExplicit, 'the constructor is code: explicit by default');
        self::assertTrue(Config::fromEnv(['INDEXNOW_KEY' => Factory::KEY, 'INDEXNOW_DRY_RUN' => '0'])->dryRunExplicit);
        self::assertFalse(Config::fromEnv(['INDEXNOW_KEY' => Factory::KEY])->dryRunExplicit);
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
