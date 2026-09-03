<?php

declare(strict_types=1);

namespace IndexNowKit\Testing;

use IndexNowKit\Http\Exception\TransportException;
use IndexNowKit\Http\Response;
use IndexNowKit\Http\TransportInterface;
use Throwable;

/**
 * Test double: records POSTs (decoded body included), answers queued responses or throws queued exceptions.
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{url: string, json: string, headers: array<string, string>, body: array<string, mixed>}> */
    public array $posts = [];

    /** @var list<string> */
    public array $gets = [];

    /** @var list<Response|Throwable> */
    private array $queue = [];

    /** @var array<string, Response|Throwable> */
    private array $getResponses = [];

    public function __construct(private readonly Response $default = new Response(200)) {}

    public function willRespond(Response|Throwable ...$responses): self
    {
        $this->queue = array_values([...$this->queue, ...$responses]);

        return $this;
    }

    public function onGet(string $url, Response|Throwable $response): self
    {
        $this->getResponses[$url] = $response;

        return $this;
    }

    public function post(string $url, string $json, array $headers = []): Response
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->posts[] = ['url' => $url, 'json' => $json, 'headers' => $headers, 'body' => $decoded];
        $next = array_shift($this->queue) ?? $this->default;
        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }

    public function get(string $url): Response
    {
        $this->gets[] = $url;
        $response = $this->getResponses[$url] ?? new Response(404, 'not found');
        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }

    public static function failing(string $message = 'connection refused'): TransportException
    {
        return new TransportException($message);
    }
}
