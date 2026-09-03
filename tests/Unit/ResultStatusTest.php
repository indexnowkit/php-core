<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\ResultStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResultStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ResultStatus, 1: bool}>
     */
    public static function successProvider(): iterable
    {
        yield 'ok is success' => [ResultStatus::Ok, true];
        yield 'pending is success' => [ResultStatus::Pending, true];
        yield 'failed is not success' => [ResultStatus::Failed, false];
        yield 'skipped is not success' => [ResultStatus::Skipped, false];
    }

    #[DataProvider('successProvider')]
    public function testIsSuccess(ResultStatus $status, bool $expected): void
    {
        self::assertSame($expected, $status->isSuccess());
    }
}
