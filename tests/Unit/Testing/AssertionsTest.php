<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Testing;

use IndexNowKit\Testing\CheckOutputAssertions;
use IndexNowKit\Testing\KeyFileAssertions;
use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The assertion helpers adapters use for H01–H05 (docs/spec/16 §5): what they accept and what they refuse.
 */
final class AssertionsTest extends TestCase
{
    #[TestDox('assertKeyFileResponse accepts the response of every adapter: header names in any case, directives in any order, list values')]
    public function testKeyFileResponseAccepts(): void
    {
        KeyFileAssertions::assertKeyFileResponse(200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'max-age=300, public', 'Vary' => 'Host'], Factory::KEY, Factory::KEY, 300, true);
        KeyFileAssertions::assertKeyFileResponse(200, ['content-type' => ['text/plain; charset=utf-8'], 'cache-control' => ['public, max-age=60'], 'vary' => ['Accept-Encoding, Host']], Factory::KEY, Factory::KEY, 60, true);
        KeyFileAssertions::assertKeyFileResponse(200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=300'], Factory::KEY, Factory::KEY);
        KeyFileAssertions::assertNotServed(404);
        $this->addToAssertionCount(1);
    }

    #[TestDox('assertKeyFileResponse refuses a wrong status, body, content type, max-age, a missing or an unexpected Vary')]
    public function testKeyFileResponseRefuses(): void
    {
        $ok = ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=300', 'Vary' => 'Host'];
        $cases = [
            'status' => [404, $ok, Factory::KEY, 300, true],
            'body' => [200, $ok, 'other', 300, true],
            'content type' => [200, ['Content-Type' => 'text/html'] + $ok, Factory::KEY, 300, true],
            'max-age' => [200, $ok, Factory::KEY, 60, true],
            'public' => [200, ['Cache-Control' => 'max-age=300, private'] + $ok, Factory::KEY, 300, true],
            'missing Vary' => [200, ['Content-Type' => $ok['Content-Type'], 'Cache-Control' => $ok['Cache-Control']], Factory::KEY, 300, true],
            'unexpected Vary' => [200, $ok, Factory::KEY, 300, false],
        ];
        foreach ($cases as $name => [$status, $headers, $body, $maxAge, $vary]) {
            try {
                KeyFileAssertions::assertKeyFileResponse($status, $headers, $body, Factory::KEY, $maxAge, $vary);
                self::fail($name . ' should fail');
            } catch (AssertionFailedError $e) {
                self::assertNotSame($name . ' should fail', $e->getMessage(), $name);
            }
        }
        try {
            KeyFileAssertions::assertNotServed(200);
            self::fail();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);
        }
    }

    #[TestDox('the check output assertions match what CheckRunner and Checker print')]
    public function testCheckOutput(): void
    {
        $ready = "www.example.com: key file OK (https://www.example.com/abcd****.txt)\nexample.de: key file OK (...)\n[OK] IndexNow is ready.\n";
        CheckOutputAssertions::assertReady($ready, 'www.example.com', 'example.de');
        CheckOutputAssertions::assertExitCode(0, 0, $ready);

        $forbidden = "www.example.com: GET https://www.example.com/abcd****.txt returned HTTP 403. Search engines will answer 403 until the key file is served with 200 (no redirects).\n[ERROR] IndexNow is not ready. Fix the errors above.\n";
        CheckOutputAssertions::assertKeyFileHint($forbidden, 403);
        CheckOutputAssertions::assertKeyFileHint($forbidden);
        CheckOutputAssertions::assertNotReady($forbidden);

        foreach ([
            static fn() => CheckOutputAssertions::assertReady($forbidden, 'www.example.com'),
            static fn() => CheckOutputAssertions::assertKeyFileHint($ready),
            static fn() => CheckOutputAssertions::assertKeyFileHint($forbidden, 404),
            static fn() => CheckOutputAssertions::assertExitCode(0, 1, $forbidden),
        ] as $i => $refused) {
            try {
                $refused();
                self::fail('case ' . $i . ' should fail');
            } catch (AssertionFailedError $e) {
                self::assertNotSame('case ' . $i . ' should fail', $e->getMessage());
            }
        }
        try {
            CheckOutputAssertions::assertExitCode(0, 1, 'the output');
        } catch (AssertionFailedError $e) {
            self::assertStringContainsString("the command printed:\nthe output", $e->getMessage());
        }
    }
}
