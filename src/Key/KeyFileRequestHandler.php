<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

use IndexNowKit\Config;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /<key>.txt` over PSR-7: the key itself (200, the headers of {@see Config::keyFileHeaders()}) for a key of the
 * requested host, 404 otherwise. {@see KeyFileResponder} decides, this class builds the PSR-7 response, so a
 * PSR-15 stack (Slim, Mezzio, Laminas, Yii3) serves the key file without a class of its own:
 *
 * - as a request handler, the action of a route matched by {@see KeyFileResponder::PATH_PATTERN} ({@see handle()});
 * - as a middleware in front of the router ({@see process()}): a request this class does not serve — a path that is not
 *   a key file, a key it does not know, a host it does not manage — goes on to the next handler untouched;
 * - as the answer for a key the router already extracted ({@see respond()}: the `{key}` argument of a route).
 *
 * The host is the request URI's host (`Host` header), the one the request arrived at: a key is served on its own host
 * only ({@see KeyProviderInterface::isKnownKey()}). No session, no CSRF: a GET of a public file.
 */
final class KeyFileRequestHandler implements RequestHandlerInterface, MiddlewareInterface
{
    public function __construct(
        private readonly KeyFileResponder $responder,
        private readonly Config $config,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {}

    /** The handler an adapter wires: `key_file.enabled` and `key_file.cache_max_age` from the Config over its key provider. */
    public static function fromConfig(Config $config, KeyProviderInterface $keys, ResponseFactoryInterface $responses, StreamFactoryInterface $streams): self
    {
        return new self(KeyFileResponder::fromConfig($config, $keys), $config, $responses, $streams);
    }

    /** RequestHandlerInterface: the key is the request path ({@see KeyFileResponder::PATH_PATTERN}); anything else is 404. */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->response($this->responder->bodyForPath($request->getUri()->getPath(), self::host($request)));
    }

    /** MiddlewareInterface: answers when the path is a key file of this host, otherwise `$handler->handle($request)`. */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = $this->responder->bodyForPath($request->getUri()->getPath(), self::host($request));

        return $body === null ? $handler->handle($request) : $this->response($body);
    }

    /**
     * The response for a key the router already extracted (Yii3: the `{key}` argument of the route, with a
     * `key_file.pattern` of the application's choosing): the key or 404, for this host.
     */
    public function respond(?string $key, ServerRequestInterface $request): ResponseInterface
    {
        return $this->response($key === null || $key === '' ? null : $this->responder->bodyForKey($key, self::host($request)));
    }

    private function response(?string $body): ResponseInterface
    {
        if ($body === null) {
            return $this->responses->createResponse(404);
        }
        $response = $this->responses->createResponse(200)->withBody($this->streams->createStream($body));
        foreach ($this->config->keyFileHeaders() as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /** The host the request arrived at, null when the URI carries none (a CLI-built request): "any managed host" for the provider. */
    private static function host(ServerRequestInterface $request): ?string
    {
        $host = $request->getUri()->getHost();

        return $host === '' ? null : $host;
    }
}
