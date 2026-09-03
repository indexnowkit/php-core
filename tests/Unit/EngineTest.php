<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Engine;
use IndexNowKit\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    /**
     * @return iterable<string, array{0: Engine, 1: string}>
     */
    public static function endpointProvider(): iterable
    {
        yield 'api' => [Engine::Api, 'https://api.indexnow.org/indexnow'];
        yield 'yandex' => [Engine::Yandex, 'https://yandex.com/indexnow'];
        yield 'bing' => [Engine::Bing, 'https://www.bing.com/indexnow'];
        yield 'naver' => [Engine::Naver, 'https://searchadvisor.naver.com/indexnow'];
        yield 'seznam' => [Engine::Seznam, 'https://search.seznam.cz/indexnow'];
        yield 'yep' => [Engine::Yep, 'https://indexnow.yep.com/indexnow'];
    }

    #[DataProvider('endpointProvider')]
    public function testEveryEngineHasItsDocumentedEndpoint(Engine $engine, string $expected): void
    {
        self::assertSame($expected, $engine->endpoint());
    }

    public function testResolveEndpointIsCaseInsensitiveForKnownEngines(): void
    {
        self::assertSame('https://yandex.com/indexnow', Engine::resolveEndpoint('YANDEX'));
        self::assertSame('https://yandex.com/indexnow', Engine::resolveEndpoint(' yandex '));
    }

    public function testResolveEndpointAcceptsCustomHttpsUrl(): void
    {
        self::assertSame('https://mock.local/indexnow', Engine::resolveEndpoint('https://mock.local/indexnow'));
    }

    public function testResolveEndpointRejectsCustomHttpUrlExceptLoopback(): void
    {
        $this->expectException(ConfigurationException::class);
        Engine::resolveEndpoint('http://mock.local/indexnow');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function loopbackProvider(): iterable
    {
        yield 'localhost' => ['http://localhost:8080/indexnow'];
        yield '127.0.0.1' => ['http://127.0.0.1:8080/indexnow'];
        yield '::1' => ['http://[::1]:8080/indexnow'];
    }

    #[DataProvider('loopbackProvider')]
    public function testResolveEndpointAcceptsCustomHttpUrlOnLoopback(string $url): void
    {
        self::assertSame($url, Engine::resolveEndpoint($url));
    }

    public function testResolveEndpointRejectsUnknownEngineName(): void
    {
        $this->expectException(ConfigurationException::class);
        Engine::resolveEndpoint('google');
    }

    public function testLabelForKnownEndpointReturnsEngineName(): void
    {
        self::assertSame('yandex', Engine::labelFor('https://yandex.com/indexnow'));
    }

    public function testLabelForCustomEndpointReturnsHost(): void
    {
        self::assertSame('mock.local', Engine::labelFor('https://mock.local/indexnow'));
    }
}
