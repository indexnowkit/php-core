<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Adapter\OptionalPackage;
use IndexNowKit\Adapter\ServicesBuilder;
use IndexNowKit\Check\Checker;
use IndexNowKit\Check\CheckItem;
use IndexNowKit\Check\CheckLevel;
use IndexNowKit\Check\CheckReport;
use IndexNowKit\Check\SampleGateCheck;
use IndexNowKit\Check\SampleOptions;
use IndexNowKit\Check\StaticCheck;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\KeyValidator;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Retry\ForbiddenCounter;
use IndexNowKit\Submitter;
use IndexNowKit\Testing\FakeTransport;
use IndexNowKit\Testing\FrozenClock;
use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionProperty;

/**
 * The core fixes of the 0.13 audit (docs/plans/audit-0.13.md): W1, S3, S4, S9, S10, A1, A8, A9, A2.
 */
final class Audit013Test extends TestCase
{
    #[TestDox('W1: dry_run, enabled, strict_hosts and collector.detect_leaks read "false"/"0"/"no" as false (an environment variable is a string)')]
    public function testBooleanOptionsFromStrings(): void
    {
        foreach (['false', '0', 'no', 'off'] as $off) {
            $config = Config::fromArray(['key' => Factory::KEY, 'dry_run' => $off, 'enabled' => $off, 'strict_hosts' => $off, 'collector' => ['detect_leaks' => $off]]);
            self::assertFalse($config->dryRun, $off);
            self::assertTrue($config->dryRunExplicit, 'an explicit off is explicit');
            self::assertFalse($config->enabled, $off);
            self::assertFalse($config->strictHosts, $off);
            self::assertFalse($config->collectorDetectLeaks, $off);
        }
        foreach (['true', '1', 'yes', 'on'] as $on) {
            $config = Config::fromArray(['key' => Factory::KEY, 'dry_run' => $on, 'strict_hosts' => $on, 'base_url' => 'https://www.example.com']);
            self::assertTrue($config->dryRun, $on);
            self::assertTrue($config->strictHosts, $on);
        }
        self::assertFalse(Config::fromArray(['key' => Factory::KEY, 'dry_run' => null])->dryRunExplicit, 'null is "not set"');
        self::assertFalse(Config::fromArray(['key' => Factory::KEY, 'dry_run' => ''])->dryRunExplicit, 'an empty environment value is "not set"');
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"dry_run" must be a boolean');
        Config::fromArray(['key' => Factory::KEY, 'dry_run' => []]);
    }

    #[TestDox('S4: baseHost() is punycode, the form every submitted URL has')]
    public function testBaseHostIsPunycode(): void
    {
        $config = Config::fromArray(['key' => Factory::KEY, 'base_url' => 'https://München.example/blog', 'strict_hosts' => true]);
        self::assertSame('xn--mnchen-3ya.example', $config->baseHost());
        self::assertSame(Factory::KEY, StaticKeyProvider::fromConfig($config)->keyFor('xn--mnchen-3ya.example'), 'strict_hosts matches the normalized host of the site');
        self::assertSame('[::1]', Config::fromArray(['key' => Factory::KEY, 'base_url' => 'https://[::1]:8443'])->baseHost());
    }

    #[TestDox('A8: the events closure may return null ("no dispatcher"); A9: requireRouter() and requireResolverLocator() name the missing node')]
    public function testEventsNullAndRequiredNodes(): void
    {
        $services = (new ServicesBuilder(Factory::config()))->events(static fn(): ?EventDispatcherInterface => null)->build();
        self::assertNull($services->events());
        self::assertNull($services->router());
        try {
            $services->requireRouter();
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('no router node (ServicesBuilder::router())', $e->getMessage());
        }
        try {
            $services->requireResolverLocator();
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('no resolver locator node', $e->getMessage());
        }
    }

    #[TestDox('A1: the clock node reaches the console submitters (--force / --dry-run) too')]
    public function testSubmitterFactoryGetsTheClock(): void
    {
        $clock = new FrozenClock('2026-09-07 12:00:00');
        $services = (new ServicesBuilder(Factory::config()))->clock($clock)->transport(new FakeTransport())->build();
        $submitter = $services->submitterFactory()->create(force: true, dryRun: false);
        self::assertInstanceOf(Submitter::class, $submitter);
        $property = new ReflectionProperty(Submitter::class, 'clock');
        self::assertSame($clock, $property->getValue($submitter));
        self::assertInstanceOf(ClockInterface::class, $property->getValue($services->submitter()));
        self::assertSame($clock, $property->getValue($services->submitter()));
    }

    #[TestDox('A2: SampleGateCheck — with the package the samples go to its check; without it a sample is an error, no sample the predicate line, all under verify.installed')]
    public function testSampleGateCheck(): void
    {
        $options = new SampleOptions();
        $lines = static function (SampleGateCheck $check): array {
            $report = new CheckReport();
            $check->check($report);

            return array_map(static fn(CheckItem $i): string => $i->level->value . ' ' . $i->code . ' ' . $i->message, $report->items());
        };
        $package = OptionalPackage::verify(false);

        self::assertSame(['ok verify.installed verify: not installed (composer require indexnowkit/verify) — pre-flight checks off'], $lines(SampleGateCheck::withoutPackage($options, $package)));
        self::assertSame(['warning verify.installed verify: not installed, the verify block in the configuration is ignored (composer require indexnowkit/verify) — pre-flight checks off'], $lines(SampleGateCheck::withoutPackage($options, $package, ['enabled' => true], ['enabled' => false])));
        $options->urls = ['https://www.example.com/x'];
        self::assertSame(['error verify.installed check --sample needs indexnowkit/verify (composer require indexnowkit/verify)'], $lines(SampleGateCheck::withoutPackage($options, $package)));

        $seen = null;
        $withPackage = SampleGateCheck::withPackage($options, static function (array $urls, array $classes) use (&$seen): StaticCheck {
            $seen = [$urls, $classes];

            return new StaticCheck(CheckLevel::Ok, 'sample ok', 'verify.sample');
        });
        $options->classes = ['App\\Post'];
        self::assertSame(['ok verify.sample sample ok'], $lines($withPackage));
        self::assertSame([['https://www.example.com/x'], ['App\\Post']], $seen, 'the options are read when the check runs, not when it is built');
        self::assertSame('verify.installed', SampleGateCheck::CODE);
    }

    #[TestDox('S10: an IPv6 literal loses the PSR-16 reserved characters in the 403 counter key')]
    public function testForbiddenCounterKeyForIpv6(): void
    {
        $counter = new ForbiddenCounter(null, 'app_', 3, 60);
        self::assertSame('app_403.__1', $counter->key('[::1]'));
        self::assertSame('app_403.__1_escalated', $counter->key('[::1]', true));
        self::assertSame('app_403.www.example.com', $counter->key('www.example.com'));
    }

    #[TestDox('S9: the key file body excerpt of a mismatch masks the previous key too (the usual mismatch after a rotation)')]
    public function testMismatchExcerptMasksThePreviousKey(): void
    {
        $old = 'previouskey12345';
        $config = Config::fromArray(['key' => Factory::KEY, 'base_url' => 'https://www.example.com', 'previous_key' => $old, 'production_environments' => ['prod'], 'environment' => 'prod']);
        $t = (new FakeTransport())
            ->onGet('https://www.example.com/' . Factory::KEY . '.txt', new Response(200, $old))
            ->onGet('https://www.example.com/' . $old . '.txt', new Response(200, $old));
        $report = (new Checker($config, StaticKeyProvider::fromConfig($config), $t))->run();
        $body = array_values(array_filter($report->items(), static fn(CheckItem $i): bool => $i->code === 'key_file.body'));
        self::assertCount(1, $body);
        self::assertStringNotContainsString($old, $body[0]->message);
        self::assertStringContainsString(KeyValidator::mask($old), $body[0]->message);
    }
}
