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

    #[TestDox('every line has a stable code; the lines about one host carry the host; a report knows its worst level')]
    public function testEveryLineHasACodeAndHostLinesCarryTheHost(): void
    {
        $t = new FakeTransport();
        $t->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $t->willRespond(new Response(202));
        $config = Factory::config(['environment' => 'staging', 'hosts' => ['b.example.com' => Factory::KEY]]);
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t, [new \IndexNowKit\Check\StaticCheck(CheckLevel::Ok, 'sitemap: not installed', 'sitemap.installed')]))->run(liveProbe: true);

        foreach ($report->items() as $item) {
            self::assertNotNull($item->code, $item->message);
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)+$/', $item->code);
            self::assertSame(str_starts_with($item->message, 'www.example.com:') || str_starts_with($item->message, 'b.example.com:'), $item->host !== null, 'host lines and only host lines carry the host: ' . $item->message);
        }
        $byCode = [];
        foreach ($report->items() as $item) {
            $byCode[$item->code][] = $item;
        }
        self::assertSame(CheckLevel::Error, $byCode['environment.non_production_submits'][0]->level);
        self::assertSame(CheckLevel::Warning, $byCode['environment.name'][0]->level);
        self::assertSame(CheckLevel::Warning, $byCode['config.strict_hosts'][0]->level, 'a hosts map without strict_hosts');
        self::assertSame(['b.example.com', 'www.example.com'], array_map(static fn(CheckItem $i): ?string => $i->host, $byCode['key_file.status']), 'the hosts map first, then the base_url host');
        self::assertSame([CheckLevel::Error, CheckLevel::Ok], array_map(static fn(CheckItem $i): CheckLevel => $i->level, $byCode['key_file.status']), 'b.example.com has no key file in the fake transport');
        self::assertSame(['b.example.com', 'www.example.com'], array_map(static fn(CheckItem $i): ?string => $i->host, $byCode['probe.response']), 'every host is probed, key file or not');
        self::assertSame(CheckLevel::Warning, $byCode['probe.response'][0]->level, 'the first probe answered 202');
        self::assertSame('sitemap.installed', $byCode['sitemap.installed'][0]->code);
        self::assertSame(CheckLevel::Error, $report->status());
        self::assertTrue($report->hasWarnings());

        $ok = new \IndexNowKit\Check\CheckReport();
        self::assertSame(CheckLevel::Ok, $ok->status());
        $ok->warning('x', 'a.b');
        self::assertSame(CheckLevel::Warning, $ok->status());
        $ok->add(new CheckItem(CheckLevel::Error, 'y', 'c.d', 'h'));
        self::assertSame(CheckLevel::Error, $ok->status());
        self::assertSame('h', $ok->items()[1]->host);
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('keyFileHeaderProvider')]
    #[TestDox('key file headers: $_dataName')]
    public function testKeyFileHeaders(array $headers, string $code, string $level, string $fragment): void
    {
        $config = Factory::config();
        $t = (new FakeTransport())->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY, headers: $headers));
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run();

        $matching = array_values(array_filter($report->items(), static fn(CheckItem $i): bool => $i->code === $code));
        self::assertCount(1, $matching, $code);
        self::assertSame($level, $matching[0]->level->value, $matching[0]->message);
        self::assertStringContainsString($fragment, $matching[0]->message);
        self::assertSame('www.example.com', $matching[0]->host);
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string, 2: string, 3: string}>
     */
    public static function keyFileHeaderProvider(): iterable
    {
        yield 'no headers at all (a transport that exposes none) is one neutral line' => [[], 'key_file.content_type', 'ok', 'Content-Type unknown (this transport does not expose headers)'];
        yield 'text/plain with a charset is ok' => [['Content-Type' => 'text/plain; charset=UTF-8'], 'key_file.content_type', 'ok', 'Content-Type text/plain'];
        yield 'another type is an error' => [['content-type' => 'text/html'], 'key_file.content_type', 'error', 'served as text/html, not text/plain'];
        yield 'headers exposed but no Content-Type is a warning' => [['Cache-Control' => 'max-age=60'], 'key_file.content_type', 'warning', 'without a Content-Type header'];
        yield 'max-age at the limit is ok' => [['Content-Type' => 'text/plain', 'Cache-Control' => 'public, max-age=300'], 'key_file.cache_control', 'ok', 'cached for at most 300s'];
        yield 'max-age over key_file.cache_max_age is a warning' => [['Content-Type' => 'text/plain', 'Cache-Control' => 'max-age=86400'], 'key_file.cache_control', 'warning', 'caching for 86400s, longer than key_file.cache_max_age (300s)'];
        yield 's-maxage wins over max-age' => [['Content-Type' => 'text/plain', 'Cache-Control' => 'max-age=60, s-maxage=3600'], 'key_file.cache_control', 'warning', 'caching for 3600s'];
        yield 'an Age over the limit is a warning about the CDN' => [['Content-Type' => 'text/plain', 'Cache-Control' => 'max-age=300', 'Age' => '7200'], 'key_file.cache_control', 'warning', 'came from a cache 7200s old'];
    }

    #[TestDox('key file headers: the cache line is absent without a Cache-Control header, and the header checks run only for a matching key file')]
    public function testKeyFileHeadersOnlyWhenTheKeyFileMatches(): void
    {
        $config = Factory::config();
        $codes = static fn(\IndexNowKit\Check\CheckReport $r): array => array_values(array_filter(array_map(static fn(CheckItem $i): ?string => $i->code, $r->items()), static fn(?string $c): bool => $c !== null && str_starts_with($c, 'key_file.')));

        $t = (new FakeTransport())->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY, headers: ['Content-Type' => 'text/plain']));
        self::assertSame(['key_file.status', 'key_file.content_type'], $codes((new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run()));

        $t = (new FakeTransport())->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, '<html>', headers: ['Content-Type' => 'text/html']));
        self::assertSame(['key_file.body'], $codes((new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run()), 'a wrong body is the error; its Content-Type is not judged on top');
    }

    #[TestDox('robots.txt: a Disallow covering the key file path (for every bot or an engine bot) is a warning; Allow wins on the longer match; other bots and 404 print nothing')]
    public function testRobotsTxt(): void
    {
        $config = Factory::config();
        $keyUrl = 'https://www.example.com/' . Factory::KEY . '.txt';
        $robots = function (?string $body, ?int $status = 200) use ($config, $keyUrl): array {
            $t = (new FakeTransport())->onGet($keyUrl, new Response(200, Factory::KEY));
            if ($body !== null) {
                $t->onGet('https://www.example.com/robots.txt', new Response($status ?? 200, $body));
            }
            $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run();
            $items = array_values(array_filter($report->items(), static fn(CheckItem $i): bool => $i->code === 'key_file.robots'));
            self::assertContains('https://www.example.com/robots.txt', $t->gets, 'robots.txt is fetched once the key file is');

            return array_map(static fn(CheckItem $i): string => $i->level->value . ' ' . $i->message, $items);
        };

        self::assertSame([], $robots(null), 'no robots.txt: nothing to say');
        self::assertSame([], $robots('User-agent: *' . "\n" . 'Disallow: /', 500));
        self::assertSame(['ok www.example.com: robots.txt does not block the key file'], $robots("User-agent: *\nDisallow: /admin/\nDisallow: /api\n"));
        self::assertSame(['warning www.example.com: robots.txt disallows the key file (Disallow: /): engines cannot fetch /' . KeyValidator::mask(Factory::KEY) . '.txt to verify the key. Allow it (Allow: /' . KeyValidator::mask(Factory::KEY) . '.txt) or move the rule.'], $robots("User-agent: *\nDisallow: /\n"));
        self::assertStringStartsWith('warning', $robots("# comment\nUser-agent: bingbot\nDisallow: /*.txt$\n")[0], 'an engine bot with a wildcard rule');
        self::assertStringStartsWith('warning', $robots("User-agent: Googlebot\nDisallow: /nothing\n\nUser-agent: YandexBot\nUser-agent: bingbot\nDisallow: /" . substr(Factory::KEY, 0, 4) . "\n")[0], 'a group of several engine bots');
        self::assertStringStartsWith('ok', $robots("User-agent: Googlebot\nDisallow: /\n")[0], 'Google does not take part in IndexNow');
        self::assertStringStartsWith('ok', $robots("User-agent: *\nDisallow: /\nAllow: /" . Factory::KEY . ".txt\n")[0], 'the longer Allow wins');
        self::assertStringStartsWith('ok', $robots("User-agent: *\nDisallow: /\nAllow: /*.txt\n")[0], 'a longer wildcard Allow wins too');
        self::assertStringStartsWith('warning', $robots("User-agent: *\nAllow: /\nDisallow: /" . Factory::KEY . ".txt\n")[0], 'the longer Disallow wins');

        self::assertNull(Checker::robotsDisallows("User-agent: *\nDisallow:\n", '/k.txt'), 'an empty Disallow allows everything');
        self::assertSame('Disallow: /k', Checker::robotsDisallows("user-agent: *\ndisallow: /k\n", '/k.txt'), 'field names are case-insensitive');
    }

    #[TestDox('previous_key: the old key file still served is ok (rotation window open); missing, another body or unreachable is a warning; per-host previous_key wins')]
    public function testPreviousKey(): void
    {
        $old = 'oldkey1234567890';
        $keyUrl = 'https://www.example.com/' . Factory::KEY . '.txt';
        $lines = function (array $overrides, FakeTransport $t): array {
            $config = Factory::config($overrides);
            $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run();

            return array_map(static fn(CheckItem $i): string => $i->level->value . ' ' . $i->message, array_values(array_filter($report->items(), static fn(CheckItem $i): bool => $i->code === 'key_file.previous')));
        };

        $t = (new FakeTransport())->onGet($keyUrl, new Response(200, Factory::KEY));
        self::assertSame([], $lines([], $t), 'no previous_key: no line');
        self::assertNotContains('https://www.example.com/' . $old . '.txt', $t->gets);

        $t = (new FakeTransport())->onGet($keyUrl, new Response(200, Factory::KEY))->onGet('https://www.example.com/' . $old . '.txt', new Response(200, $old . "\n"));
        self::assertSame(['ok www.example.com: previous key file OK (https://www.example.com/' . KeyValidator::mask($old) . '.txt): rotation window open; remove previous_key once check --live is green'], $lines(['previous_key' => $old], $t));

        $t = (new FakeTransport())->onGet($keyUrl, new Response(200, Factory::KEY));
        $warning = $lines(['previous_key' => $old], $t);
        self::assertCount(1, $warning);
        self::assertStringStartsWith('warning www.example.com: previous_key is set but https://www.example.com/' . KeyValidator::mask($old) . '.txt answers HTTP 404:', $warning[0]);
        self::assertStringNotContainsString($old, $warning[0], 'the old key is masked too');

        $t = (new FakeTransport())->onGet($keyUrl, new Response(200, Factory::KEY))->onGet('https://www.example.com/' . $old . '.txt', new Response(200, 'something else'));
        self::assertStringContainsString('answers HTTP 200 with another body', $lines(['previous_key' => $old], $t)[0]);

        $t = (new FakeTransport())->onGet($keyUrl, new Response(200, Factory::KEY))->onGet('https://www.example.com/' . $old . '.txt', FakeTransport::failing('timeout'));
        self::assertStringContainsString('cannot be fetched (timeout)', $lines(['previous_key' => $old], $t)[0]);

        $perHost = 'perhostkey123456';
        $t = (new FakeTransport())->onGet($keyUrl, new Response(200, Factory::KEY))->onGet('https://www.example.com/' . $perHost . '.txt', new Response(200, $perHost));
        self::assertStringStartsWith('ok', $lines(['previous_key' => $old, 'hosts' => ['www.example.com' => ['key' => Factory::KEY, 'previous_key' => $perHost]]], $t)[0], 'hosts.<host>.previous_key wins over previous_key');
    }

    public function testCustomHttpClientIsAWarningAboutRedirects(): void
    {
        $config = Factory::config(['http' => ['client' => 'my.client']]);
        $t = (new FakeTransport())->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, Factory::KEY));
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run();

        $items = array_values(array_filter($report->items(), static fn(CheckItem $i): bool => $i->code === 'http.client'));
        self::assertCount(1, $items);
        self::assertSame(CheckLevel::Warning, $items[0]->level);
        self::assertStringContainsString('"my.client"', $items[0]->message);
        self::assertStringContainsString('follows redirects', $items[0]->message);
        self::assertNull($items[0]->host, 'one global line, not one per host');
        self::assertSame([], array_filter((new Checker(Factory::config(), StaticKeyProvider::fromConfig(Factory::config()), $t))->run()->items(), static fn(CheckItem $i): bool => $i->code === 'http.client'), 'silent with the discovered client (check already uses one without redirects)');
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
