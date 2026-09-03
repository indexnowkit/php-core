<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Dispatch\NullDispatcher;
use PHPUnit\Framework\TestCase;

final class NullDispatcherTest extends TestCase
{
    public function testDispatchDropsUrlsWithoutError(): void
    {
        (new NullDispatcher())->dispatch(['/a', '/b']);
        $this->addToAssertionCount(1);
    }
}
