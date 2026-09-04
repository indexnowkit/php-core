<?php

declare(strict_types=1);

namespace IndexNowKit\Http;

use Closure;
use IndexNowKit\Config;
use IndexNowKit\Exception\ConfigurationException;
use Psr\Http\Client\ClientInterface;

/**
 * The transport an adapter wires from `http.client` and `http.timeout`: built on first use only, so a request that
 * submits nothing never pays for client discovery and never fails on a missing PSR-18 client.
 */
final class TransportFactory
{
    private function __construct() {}

    /**
     * `http.client` unset: PSR-18 discovery with the timeout. Set: the adapter's locator resolves the id (a
     * container binding, a service id, a class name) and the result must be a PSR-18 client.
     *
     * @param (Closure(string): mixed)|null $clientLocator how the adapter resolves `http.client`; required when it is set
     */
    public static function lazy(Config $config, ?Closure $clientLocator = null): LazyTransport
    {
        $id = $config->httpClient;
        if ($id !== null && $clientLocator === null) {
            throw new ConfigurationException(\sprintf('"http.client" is "%s" but this adapter has no way to resolve it; pass a client locator or unset the option.', $id));
        }

        return new LazyTransport(static function () use ($config, $id, $clientLocator): TransportInterface {
            if ($id === null || $clientLocator === null) {
                return Psr18Transport::discover(timeout: $config->httpTimeout);
            }

            return Psr18Transport::discover(self::psr18($clientLocator($id), $id), $config->httpTimeout);
        });
    }

    /**
     * What an `http.client` id resolved to, checked to be a PSR-18 client.
     *
     * @throws ConfigurationException with the id and the type it resolved to
     */
    public static function psr18(mixed $instance, string $id): ClientInterface
    {
        if (!$instance instanceof ClientInterface) {
            throw new ConfigurationException(\sprintf('"http.client" "%s" resolves to %s, which is not a PSR-18 client.', $id, get_debug_type($instance)));
        }

        return $instance;
    }
}
