<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Key\KeyFileResponder;
use IndexNowKit\Key\StaticKeyProvider;
use IndexNowKit\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;

final class KeyFileResponderTest extends TestCase
{
    public function testBodyForPathMatchesTheKeyFilePattern(): void
    {
        $responder = new KeyFileResponder(new StaticKeyProvider(Factory::KEY));

        self::assertSame(Factory::KEY, $responder->bodyForPath('/' . Factory::KEY . '.txt'));
    }

    public function testBodyForPathReturnsNullWhenThePathDoesNotMatch(): void
    {
        $responder = new KeyFileResponder(new StaticKeyProvider(Factory::KEY));

        self::assertNull($responder->bodyForPath('/robots.txt'));
        self::assertNull($responder->bodyForPath('/short.txt'), 'shorter than KeyValidator::MIN_LENGTH, rejected by the path pattern itself');
    }

    public function testBodyForKeyReturnsNullForAnUnknownKey(): void
    {
        $responder = new KeyFileResponder(new StaticKeyProvider(Factory::KEY));

        self::assertNull($responder->bodyForKey('unknownkey1234'));
    }

    public function testBodyForKeyReturnsNullWhenDisabled(): void
    {
        $responder = new KeyFileResponder(new StaticKeyProvider(Factory::KEY), enabled: false);

        self::assertNull($responder->bodyForKey(Factory::KEY));
    }

    public function testBodyForKeyScopesTheLookupToTheGivenHost(): void
    {
        $responder = new KeyFileResponder(new StaticKeyProvider(null, ['a.example.com' => 'hostkeyA1234', 'b.example.com' => 'hostkeyB1234']));

        self::assertSame('hostkeyA1234', $responder->bodyForKey('hostkeyA1234', 'a.example.com'));
        self::assertNull($responder->bodyForKey('hostkeyA1234', 'b.example.com'));
    }

    public function testHeadersDefaultToTheDefaultMaxAge(): void
    {
        $headers = KeyFileResponder::headers();

        self::assertSame('text/plain; charset=utf-8', $headers['Content-Type']);
        self::assertStringContainsString('max-age=300', $headers['Cache-Control']);
    }

    public function testHeadersAcceptACustomMaxAge(): void
    {
        $headers = KeyFileResponder::headers(60);

        self::assertStringContainsString('max-age=60', $headers['Cache-Control']);
    }

    public function testHeadersClampNegativeMaxAgeToZero(): void
    {
        $headers = KeyFileResponder::headers(-10);

        self::assertStringContainsString('max-age=0', $headers['Cache-Control']);
    }
}
