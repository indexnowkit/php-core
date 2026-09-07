<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Key;

use IndexNowKit\Key\KeyFileRequestHandler;
use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Tests\Support\Factory;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The next handler of the middleware test: records whether it was reached and answers 418. */
final class NextHandler implements RequestHandlerInterface
{
    public bool $reached = false;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->reached = true;

        return (new Psr17Factory())->createResponse(418);
    }
}

/**
 * The PSR-15 key file handler over nyholm/psr7: as a route handler, as a middleware, and for a key the router
 * extracted; per host, with the headers of `Config::keyFileHeaders()`.
 */
final class KeyFileRequestHandlerTest extends TestCase
{
    private const SECOND_KEY = 'fedcba0987654321fedcba0987654321';

    /**
     * @param array<string, mixed> $overrides
     */
    private static function handler(array $overrides = []): KeyFileRequestHandler
    {
        $config = Factory::config($overrides);
        $factory = new Psr17Factory();

        return KeyFileRequestHandler::fromConfig($config, StaticKeyProvider::fromConfig($config), $factory, $factory);
    }

    private static function request(string $url): ServerRequestInterface
    {
        return new ServerRequest('GET', $url);
    }

    #[TestDox('H01 handle(): GET /<key>.txt of the base host -> 200 text/plain with the key and a short Cache-Control')]
    public function testHandleServesTheKeyOfTheBaseHost(): void
    {
        $response = self::handler()->handle(self::request('https://www.example.com/' . Factory::KEY . '.txt'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Factory::KEY, (string) $response->getBody());
        self::assertSame(KeyFileResponder::CONTENT_TYPE, $response->getHeaderLine('Content-Type'));
        self::assertSame('public, max-age=300', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Vary'), 'one host, no hosts map: the body does not depend on the host');
    }

    #[TestDox('H02 handle(): an unknown key, a path that is not a key file, or the key of another host -> 404')]
    public function testHandleAnswers404ForWhatIsNotThisHostsKeyFile(): void
    {
        $handler = self::handler(['hosts' => ['example.de' => self::SECOND_KEY]]);

        self::assertSame(404, $handler->handle(self::request('https://www.example.com/abcdefghijklmnop.txt'))->getStatusCode());
        self::assertSame(404, $handler->handle(self::request('https://www.example.com/robots.txt'))->getStatusCode());
        self::assertSame(404, $handler->handle(self::request('https://www.example.com/' . Factory::KEY))->getStatusCode(), 'no .txt');
        self::assertSame(404, $handler->handle(self::request('https://www.example.com/' . self::SECOND_KEY . '.txt'))->getStatusCode(), 'the key of example.de is not served on the base host');
        self::assertSame(200, $handler->handle(self::request('https://example.de/' . self::SECOND_KEY . '.txt'))->getStatusCode());
    }

    #[TestDox('a hosts map or strict_hosts adds Vary: Host (a shared cache must not keep one host\'s answer for another)')]
    public function testVaryHostWithAHostsMapOrStrictHosts(): void
    {
        $withMap = self::handler(['hosts' => ['example.de' => self::SECOND_KEY]])->handle(self::request('https://www.example.com/' . Factory::KEY . '.txt'));
        self::assertSame('Host', $withMap->getHeaderLine('Vary'));

        $strict = self::handler(['strict_hosts' => true])->handle(self::request('https://www.example.com/' . Factory::KEY . '.txt'));
        self::assertSame('Host', $strict->getHeaderLine('Vary'));
        self::assertSame(404, self::handler(['strict_hosts' => true])->handle(self::request('https://other.example.com/' . Factory::KEY . '.txt'))->getStatusCode(), 'strict_hosts: the default key answers on the base host only');
    }

    #[TestDox('key_file.cache_max_age is the max-age of the response')]
    public function testCacheMaxAge(): void
    {
        $response = self::handler(['key_file' => ['cache_max_age' => 60]])->handle(self::request('https://www.example.com/' . Factory::KEY . '.txt'));

        self::assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
    }

    #[TestDox('key_file.enabled: false -> 404 for the right key too (the web server serves the file then)')]
    public function testDisabledKeyFile(): void
    {
        $handler = self::handler(['key_file' => ['enabled' => false]]);

        self::assertSame(404, $handler->handle(self::request('https://www.example.com/' . Factory::KEY . '.txt'))->getStatusCode());
        self::assertSame(404, $handler->respond(Factory::KEY, self::request('https://www.example.com/' . Factory::KEY . '.txt'))->getStatusCode());
    }

    #[TestDox('process(): a key file of this host is answered here; anything else goes on to the next handler untouched')]
    public function testMiddlewarePassesOnWhatItDoesNotServe(): void
    {
        $handler = self::handler(['hosts' => ['example.de' => self::SECOND_KEY]]);

        $next = new NextHandler();
        $response = $handler->process(self::request('https://www.example.com/' . Factory::KEY . '.txt'), $next);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Factory::KEY, (string) $response->getBody());
        self::assertFalse($next->reached, 'served by the middleware, the router never saw it');

        foreach (['/about', '/robots.txt', '/abcdefghijklmnop.txt', '/' . self::SECOND_KEY . '.txt'] as $path) {
            $next = new NextHandler();
            $response = $handler->process(self::request('https://www.example.com' . $path), $next);
            self::assertTrue($next->reached, $path . ' goes on to the application');
            self::assertSame(418, $response->getStatusCode(), $path);
        }
    }

    #[TestDox('respond(): the key the router extracted, for this host; an unknown key, an empty one or null -> 404')]
    public function testRespondForAnExtractedKey(): void
    {
        $handler = self::handler(['hosts' => ['example.de' => self::SECOND_KEY]]);
        $base = self::request('https://www.example.com/anything');

        $response = $handler->respond(Factory::KEY, $base);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(Factory::KEY, (string) $response->getBody());
        self::assertSame('Host', $response->getHeaderLine('Vary'));

        self::assertSame(404, $handler->respond(self::SECOND_KEY, $base)->getStatusCode(), 'the key of another host');
        self::assertSame(200, $handler->respond(self::SECOND_KEY, self::request('https://example.de/anything'))->getStatusCode());
        self::assertSame(404, $handler->respond('unknownkey1234', $base)->getStatusCode());
        self::assertSame(404, $handler->respond('', $base)->getStatusCode());
        self::assertSame(404, $handler->respond(null, $base)->getStatusCode());
    }

    #[TestDox('a request without a host (a CLI-built one) is "any managed host" for the provider, like the responder')]
    public function testRequestWithoutAHost(): void
    {
        $response = self::handler()->handle(self::request('/' . Factory::KEY . '.txt'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[TestDox('the constructor takes a responder of its own (an application key provider), the Config only for the headers')]
    public function testConstructorOverAResponder(): void
    {
        $config = Factory::config();
        $factory = new Psr17Factory();
        $handler = new KeyFileRequestHandler(new KeyFileResponder(new StaticKeyProvider('customkey12345678')), $config, $factory, $factory);

        self::assertSame(200, $handler->handle(self::request('https://www.example.com/customkey12345678.txt'))->getStatusCode());
        self::assertSame(404, $handler->handle(self::request('https://www.example.com/' . Factory::KEY . '.txt'))->getStatusCode(), 'the Config key is not what this responder knows');
    }
}
