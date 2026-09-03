<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Http\Psr18Transport;
use IndexNowKit\Tests\Support\Factory;
use IndexNowKit\Tests\Support\MockServer;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * Integration with the PHP mock server (packages/core/tests/Support/mock-server/router.php) through a real PSR-18 client.
 */
#[Group('integration')]
final class Psr18TransportTest extends TestCase
{
    private static MockServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = MockServer::start([Factory::KEY]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    public function testScenariosThroughRealHttp(): void
    {
        $factory = new Psr17Factory();
        $client = new Psr18Client();
        $base = self::$server->baseUrl();

        $transport = new Psr18Transport($client, $factory, $factory, ['X-Mock-Scenario' => 'ratelimit429']);
        $r = $transport->post($base . '/indexnow', '{"host":"www.example.com","key":"' . Factory::KEY . '","urlList":["https://www.example.com/a"]}');
        self::assertSame(429, $r->status);
        self::assertSame(2, $r->retryAfter);

        $transport = new Psr18Transport($client, $factory, $factory, ['X-Mock-Scenario' => 'ok200']);
        self::assertSame(200, $transport->post($base . '/indexnow', '{"host":"www.example.com","key":"' . Factory::KEY . '","urlList":["https://www.example.com/a"]}')->status);
        self::assertSame(422, $transport->post($base . '/indexnow', '{"host":"www.example.com","key":"' . Factory::KEY . '","urlList":["https://other.com/a"]}')->status);
        self::assertSame(400, $transport->post($base . '/indexnow', '{"nope":1}')->status);

        $key = $transport->get($base . '/' . Factory::KEY . '.txt');
        self::assertSame(200, $key->status);
        self::assertSame(Factory::KEY, $key->body);
        self::assertSame(404, $transport->get($base . '/otherkey123.txt')->status);

        $log = self::$server->requests();
        self::assertCount(4, $log);
        self::assertSame('www.example.com', $log[1]['json']['host']);
    }
}
