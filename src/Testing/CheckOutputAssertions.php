<?php

declare(strict_types=1);

namespace IndexNowKit\Testing;

use PHPUnit\Framework\Assert;

/**
 * What the output of the `check` command must say (conformance H04–H05), for the command test of any adapter:
 * capture exit code and output the framework's way, and assert here. The phrases are the ones `Console\CheckRunner`
 * and `Check\Checker` print, so an adapter test does not carry its own copy of them.
 */
final class CheckOutputAssertions
{
    private function __construct() {}

    /** The exit code, with the whole output as the failure message. */
    public static function assertExitCode(int $expected, int $actual, string $output): void
    {
        Assert::assertSame($expected, $actual, "exit code; the command printed:\n" . $output);
    }

    /** H04: every host's key file is reachable and the closing line is the success line. */
    public static function assertReady(string $output, string ...$hosts): void
    {
        foreach ($hosts as $host) {
            Assert::assertStringContainsString($host . ': key file OK', $output, \sprintf('the key file of %s was fetched and matched', $host));
        }
        Assert::assertStringContainsString('IndexNow is ready.', $output);
        Assert::assertStringNotContainsString('IndexNow is not ready', $output);
    }

    /** H05: the key file could not be fetched or did not match, and the run says so. */
    public static function assertNotReady(string $output): void
    {
        Assert::assertStringContainsString('IndexNow is not ready', $output);
    }

    /**
     * H05: the key file failed with the HTTP status given, and the operator got the hint about what the engines
     * do with it (`Search engines will answer 403 until the key file is served with 200`).
     */
    public static function assertKeyFileHint(string $output, ?int $httpStatus = null): void
    {
        if ($httpStatus !== null) {
            Assert::assertStringContainsString(\sprintf('returned HTTP %d', $httpStatus), $output, 'the status the key file answered is named');
        }
        Assert::assertStringContainsString('until the key file is served with 200', $output, 'the hint explains what the engines will do');
        self::assertNotReady($output);
    }
}
