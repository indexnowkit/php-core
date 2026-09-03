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
use Throwable;

/**
 * PSR-18 transport. POST responses are read up to 2 KiB (diagnostics only); GET responses up to 50 MiB
 * (the sitemap protocol maximum). {@see download()} streams a GET body into a sink so large documents never
 * live in memory.
 */
final class Psr18Transport implements StreamingTransportInterface
{
    /** Default of $postBodyLimit: a submission response is diagnostics only. */
    public const POST_BODY_LIMIT = 2048;
    public const GET_BODY_LIMIT = 52_428_800;
    /** Default of $maxRetryAfter. */
    public const MAX_RETRY_AFTER = Response::MAX_RETRY_AFTER;

    private const READ_CHUNK = 65536;

    /**
     * @param array<string, string> $extraHeaders  sent with every request (tests use X-Mock-Scenario)
     * @param int                   $getBodyLimit  bytes of a GET body (sitemaps, key files) before the request fails
     * @param int                   $postBodyLimit bytes of a POST response kept for diagnostics; the rest is discarded
     * @param int                   $maxRetryAfter clamp of a parsed Retry-After header, seconds
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly array $extraHeaders = [],
        private readonly int $getBodyLimit = self::GET_BODY_LIMIT,
        private readonly int $postBodyLimit = self::POST_BODY_LIMIT,
        private readonly int $maxRetryAfter = self::MAX_RETRY_AFTER,
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

        return $this->send($request, $this->postBodyLimit, post: true);
    }

    public function get(string $url): Response
    {
        return $this->send($this->getRequest($url), $this->getBodyLimit, post: false);
    }

    public function download(string $url, $sink): Response
    {
        $request = $this->getRequest($url);
        $response = $this->sendRequest($request);
        $body = $response->getBody();
        if ($body->isReadable()) {
            $read = $this->copyBody($body, $this->getBodyLimit, $request, static function (string $chunk) use ($sink, $request): void {
                if (@fwrite($sink, $chunk) !== \strlen($chunk)) {
                    throw new TransportException(\sprintf('GET %s: cannot write the response body to the sink.', $request->getUri()->getHost()));
                }
            });
            self::assertComplete($response, $read, $request);
        }

        return new Response($response->getStatusCode(), '', $this->retryAfter($response));
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

            return self::instantiate($guzzle, $options);
        }

        return Psr18ClientDiscovery::find();
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $options
     *
     * @throws ConfigurationException when the class is not a PSR-18 client (a Guzzle older than 7)
     */
    private static function instantiate(string $class, array $options): ClientInterface
    {
        $client = new $class($options);
        if (!$client instanceof ClientInterface) {
            throw new ConfigurationException(\sprintf('%s is not a PSR-18 client; install a PSR-18 implementation (guzzlehttp/guzzle ^7, symfony/http-client + nyholm/psr7).', $class));
        }

        return $client;
    }

    private function send(RequestInterface $request, int $bodyLimit, bool $post): Response
    {
        $response = $this->sendRequest($request);
        $content = $this->readBody($response->getBody(), $bodyLimit, $request, $post);
        if (!$post) {
            self::assertComplete($response, \strlen($content), $request);
        }

        return new Response($response->getStatusCode(), $content, $this->retryAfter($response));
    }

    private function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            return $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(\sprintf('%s %s failed: %s', $request->getMethod(), $request->getUri()->getHost(), $e->getMessage()), 0, $e);
        }
    }

    private function readBody(StreamInterface $body, int $limit, RequestInterface $request, bool $truncate): string
    {
        if (!$body->isReadable()) {
            return '';
        }
        $content = '';
        $this->copyBody($body, $limit, $request, static function (string $chunk) use (&$content): void {
            $content .= $chunk;
        }, $truncate);

        return $content;
    }

    /**
     * Reads $body in READ_CHUNK pieces and hands each to $write; a body over $limit is truncated ($truncate, POST
     * diagnostics) or rejected (GET). A connection that drops mid-body surfaces as a TransportException naming the
     * bytes read.
     *
     * @param callable(string): void $write
     *
     * @return int bytes read
     */
    private function copyBody(StreamInterface $body, int $limit, RequestInterface $request, callable $write, bool $truncate = false): int
    {
        $read = 0;
        try {
            while (!$body->eof() && $read < $limit) {
                $chunk = $body->read(min(self::READ_CHUNK, $limit - $read));
                if ($chunk === '') {
                    break;
                }
                $read += \strlen($chunk);
                $write($chunk);
            }
            $overflow = $read >= $limit && !$body->eof() && $body->read(1) !== '';
        } catch (TransportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new TransportException(\sprintf('%s %s: connection lost after %d bytes: %s', $request->getMethod(), $request->getUri()->getHost(), $read, $e->getMessage()), 0, $e);
        }
        if ($overflow && !$truncate) {
            throw new TransportException(\sprintf('%s %s: response body larger than %d bytes.', $request->getMethod(), $request->getUri()->getHost(), $limit));
        }

        return $read;
    }

    /**
     * A body shorter than the announced Content-Length is a truncated download, not a document.
     */
    private static function assertComplete(ResponseInterface $response, int $read, RequestInterface $request): void
    {
        $length = $response->getHeaderLine('Content-Length');
        if ($length !== '' && preg_match('/^\d+$/', $length) === 1 && $read < (int) $length && $response->getHeaderLine('Content-Encoding') === '') {
            throw new TransportException(\sprintf('%s %s: response truncated, %d of %s bytes received.', $request->getMethod(), $request->getUri()->getHost(), $read, $length));
        }
    }

    /**
     * Retry-After as delay seconds (RFC 9110 §10.2.3: delta-seconds or HTTP-date), clamped.
     */
    private function retryAfter(ResponseInterface $response): ?int
    {
        return Response::parseRetryAfter($response->getHeaderLine('Retry-After'), $this->maxRetryAfter);
    }
}
