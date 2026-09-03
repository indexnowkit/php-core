<?php

declare(strict_types=1);

namespace IndexNowKit\Http;

use Closure;
use IndexNowKit\Exception\ConfigurationException;

/**
 * Defers building the real transport (PSR-18 discovery, client construction) until the first request, so a
 * request that submits nothing, a dry-run setup or `indexnow check` never pays for it and never fails on it.
 */
final class LazyTransport implements TransportInterface
{
    private ?TransportInterface $transport = null;

    /**
     * @param Closure(): TransportInterface $factory
     */
    public function __construct(private readonly Closure $factory) {}

    public function post(string $url, string $json, array $headers = []): Response
    {
        return $this->transport()->post($url, $json, $headers);
    }

    public function get(string $url): Response
    {
        return $this->transport()->get($url);
    }

    /**
     * @throws ConfigurationException when the factory cannot build a transport (no PSR-18 client installed)
     */
    public function transport(): TransportInterface
    {
        return $this->transport ??= ($this->factory)();
    }
}
