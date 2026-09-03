<?php

declare(strict_types=1);

namespace IndexNowKit\Http;

use Http\Discovery\Exception\NotFoundException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Http\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * PSR-18 transport. POST responses are read up to 2 KiB (diagnostics only); GET responses up to 50 MiB
 * (the sitemap protocol maximum). {@see download()} streams a GET body into a sink so large documents never
 * live in memory.
 */
final class Psr18Transport implements StreamingTransportInterface
{
    public const POST_BODY_LIMIT = 2048;
    public const GET_BODY_LIMIT = 52_428_800;
    public const MAX_RETRY_AFTER = Response::MAX_RETRY_AFTER;

    private const READ_CHUNK = 65536;

    /**
     * @param array<string, string> $extraHeaders sent with every request (tests use X-Mock-Scenario)
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly array $extraHeaders = [],
        private readonly int $getBodyLimit = self::GET_BODY_LIMIT,
    ) {}

    /**
     * Builds a transport around the given PSR-18 client, or discovers one.
     *
     * Without an explicit client, symfony/http-client or guzzlehttp/guzzle (whichever is installed) is
     * configured with $timeout and without redirects; any other discovered client keeps its own defaults.
     *
     * @param float|null $timeout seconds, applied only to clients this method creates
     *
     * @throws ConfigurationException when no PSR-18 client or PSR-17 factories can be found
     */
    public static function discover(?ClientInterface $client = null, ?float $timeout = null): self
    {
        try {
            return new self(
                $client ?? self::createClient($timeout),
                Psr17FactoryDiscovery::findRequestFactory(),
                Psr17FactoryDiscovery::findStreamFactory(),
            );
        } catch (NotFoundException $e) {
            throw new ConfigurationException('No PSR-18 HTTP client / PSR-17 factories found. Install e.g. "symfony/http-client" and "nyholm/psr7", or pass a client explicitly.', 0, $e);
        }
    }

    public function post(string $url, string $json, array $headers = []): Response
    {
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream($json));
        foreach ($headers + $this->extraHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->send($request, self::POST_BODY_LIMIT);
    }

    public function get(string $url): Response
    {
        return $this->send($this->getRequest($url), $this->getBodyLimit);
    }

    public function download(string $url, $sink): Response
    {
        $request = $this->getRequest($url);
        $response = $this->sendRequest($request);
        $body = $response->getBody();
        if ($body->isReadable()) {
            $this->copyBody($body, $this->getBodyLimit, $request, static function (string $chunk) use ($sink, $request): void {
                if (@fwrite($sink, $chunk) !== \strlen($chunk)) {
                    throw new TransportException(\sprintf('GET %s: cannot write the response body to the sink.', $request->getUri()->getHost()));
                }
            });
        }

        return new Response($response->getStatusCode(), '', self::retryAfter($response));
    }

    private function getRequest(string $url): RequestInterface
    {
        $request = $this->requestFactory->createRequest('GET', $url);
        foreach ($this->extraHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    /**
     * @param float|null $timeout seconds
     */
    private static function createClient(?float $timeout): ClientInterface
    {
        if (class_exists(Psr18Client::class) && class_exists(HttpClient::class)) {
            $options = ['max_redirects' => 0];
            if ($timeout !== null) {
                $options['timeout'] = $timeout;
                $options['max_duration'] = $timeout * 2;
            }

            return new Psr18Client(HttpClient::create($options));
        }
        $guzzle = 'GuzzleHttp\\Client';
        if (class_exists($guzzle)) {
            $options = ['allow_redirects' => false, 'http_errors' => false];
            if ($timeout !== null) {
                $options['timeout'] = $timeout;
                $options['connect_timeout'] = $timeout;
            }

            /** @var ClientInterface */
            return new $guzzle($options);
        }

        return Psr18ClientDiscovery::find();
    }

    private function send(RequestInterface $request, int $bodyLimit): Response
    {
        $response = $this->sendRequest($request);

        return new Response($response->getStatusCode(), $this->readBody($response->getBody(), $bodyLimit, $request), self::retryAfter($response));
    }

    private function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(\sprintf('%s %s failed: %s', $request->getMethod(), $request->getUri()->getHost(), $e->getMessage()), 0, $e);
        }
    }

    private function readBody(StreamInterface $body, int $limit, RequestInterface $request): string
    {
        if (!$body->isReadable()) {
            return '';
        }
        $content = '';
        $this->copyBody($body, $limit, $request, static function (string $chunk) use (&$content): void {
            $content .= $chunk;
        });

        return $content;
    }

    /**
     * Reads $body in READ_CHUNK pieces and hands each to $write; a body over $limit is truncated (POST diagnostics)
     * or rejected (GET).
     *
     * @param callable(string): void $write
     */
    private function copyBody(StreamInterface $body, int $limit, RequestInterface $request, callable $write): void
    {
        $read = 0;
        while (!$body->eof() && $read < $limit) {
            $chunk = $body->read(min(self::READ_CHUNK, $limit - $read));
            if ($chunk === '') {
                break;
            }
            $read += \strlen($chunk);
            $write($chunk);
        }
        if ($read >= $limit && !$body->eof() && $body->read(1) !== '' && $limit !== self::POST_BODY_LIMIT) {
            throw new TransportException(\sprintf('%s %s: response body larger than %d bytes.', $request->getMethod(), $request->getUri()->getHost(), $limit));
        }
    }

    /**
     * Retry-After as delay seconds (RFC 9110 §10.2.3: delta-seconds or HTTP-date), clamped.
     */
    private static function retryAfter(ResponseInterface $response): ?int
    {
        return Response::parseRetryAfter($response->getHeaderLine('Retry-After'), self::MAX_RETRY_AFTER);
    }
}
