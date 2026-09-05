<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Reason;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

final class ReasonTest extends TestCase
{
    #[TestDox('the reserved verify cases are skips; origin_error is the retryable one; every case has a message and a translation key')]
    public function testVerifyCasesAndFlags(): void
    {
        $skips = ['disabled', 'dry_run', 'debounced', 'no_key', 'invalid_url', 'noindex', 'robots_disallowed', 'non_canonical', 'redirected', 'origin_error'];
        $retryable = ['rate_limited', 'server_error', 'transport', 'origin_error'];
        foreach (Reason::cases() as $reason) {
            self::assertSame(\in_array($reason->value, $skips, true), $reason->isSkip(), $reason->value);
            self::assertSame(\in_array($reason->value, $retryable, true), $reason->isRetryable(), $reason->value);
            self::assertNotSame('', $reason->message());
            self::assertSame('indexnowkit.reason.' . $reason->value, $reason->translationKey());
        }
        self::assertSame(Reason::Noindex, Reason::from('noindex'));
        self::assertSame(Reason::RobotsDisallowed, Reason::from('robots_disallowed'));
        self::assertSame(Reason::NonCanonical, Reason::from('non_canonical'));
        self::assertSame(Reason::Redirected, Reason::from('redirected'));
        self::assertSame(Reason::OriginError, Reason::from('origin_error'));
        self::assertCount(17, Reason::cases());
    }
}
