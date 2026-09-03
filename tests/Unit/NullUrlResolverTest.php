<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\NullUrlResolver;
use PHPUnit\Framework\TestCase;
use stdClass;

final class NullUrlResolverTest extends TestCase
{
    public function testAlwaysThrowsConfigurationException(): void
    {
        $this->expectException(ConfigurationException::class);
        iterator_to_array((new NullUrlResolver())->resolve(new stdClass(), Event::Updated));
    }
}
