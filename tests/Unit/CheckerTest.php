<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Check\Checker;
use IndexNowKit\Http\Response;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Tests\Support\FakeTransport;
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
}
