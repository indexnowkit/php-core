<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Support;

use RuntimeException;

/**
 * Starts tools/mock-server/router.php with PHP's built-in server for integration tests.
 */
final class MockServer
{
    /** @var resource */
    private $process;

    private function __construct(private readonly string $host, private readonly int $port, $process)
    {
        $this->process = $process;
    }

    /**
     * @param list<string> $keys
     */
    public static function start(array $keys = [], string $host = '127.0.0.1'): self
    {
        $port = self::freePort();
        $router = \dirname(__DIR__, 4) . '/tools/mock-server/router.php';
        $cmd = \sprintf('MOCK_KEYS=%s exec php -S %s:%d %s', escapeshellarg(implode(',', $keys)), $host, $port, escapeshellarg($router));
        $process = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new RuntimeException('Cannot start mock server.');
        }
        $server = new self($host, $port, $process);
        for ($i = 0; $i < 50; ++$i) {
            usleep(100_000);
            $sock = @fsockopen($host, $port, $errno, $errstr, 0.2);
            if (\is_resource($sock)) {
                fclose($sock);
                $server->clear();

                return $server;
            }
        }
        $server->stop();

        throw new RuntimeException('Mock server did not start.');
    }

    public function baseUrl(): string
    {
        return \sprintf('http://%s:%d', $this->host, $this->port);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requests(): array
    {
        /** @var list<array<string, mixed>> $log */
        $log = json_decode((string) file_get_contents($this->baseUrl() . '/_mock/requests'), true) ?: [];

        return $log;
    }

    public function clear(): void
    {
        file_get_contents($this->baseUrl() . '/_mock/requests', false, stream_context_create(['http' => ['method' => 'DELETE']]));
    }

    public function stop(): void
    {
        if (\is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($sock === false) {
            throw new RuntimeException('Cannot allocate port: ' . $errstr);
        }
        $name = stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }
}
