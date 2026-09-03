<?php

declare(strict_types=1);

namespace IndexNowKit\Http;

use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use IndexNowKit\Http\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Psr18Transport implements TransportInterface
{
    private const BODY_LIMIT = 2048;

    /**
     * @param array<string, string> $extraHeaders sent with every request (tests use X-Mock-Scenario)
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly array $extraHeaders = [],
    ) {}

    public static function discover(?ClientInterface $client = null): self
    {
        return new self(
            $client ?? Psr18ClientDiscovery::find(),
            Psr17FactoryDiscovery::findRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
        );
    }

    public function post(string $url, string $json, array $headers = []): Response
    {
        $request = $this->requestFactory->createRequest('POST', $url)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streamFactory->createStream($json));
        foreach ($this->extraHeaders + $headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->send($request);
    }

    public function get(string $url): Response
    {
        $request = $this->requestFactory->createRequest('GET', $url);
        foreach ($this->extraHeaders as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->send($request);
    }

    private function send(\Psr\Http\Message\RequestInterface $request): Response
    {
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new TransportException(\sprintf('%s %s failed: %s', $request->getMethod(), $request->getUri(), $e->getMessage()), 0, $e);
        }

        return self::fromPsr($response);
    }

    private static function fromPsr(ResponseInterface $response): Response
    {
        $body = $response->getBody();
        $content = $body->isReadable() ? (string) $body->read(self::BODY_LIMIT) : '';
        $retryAfter = $response->getHeaderLine('Retry-After');

        return new Response($response->getStatusCode(), $content, is_numeric($retryAfter) ? (int) $retryAfter : null);
    }
}
