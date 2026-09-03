<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\CallableUrlResolver;
use IndexNowKit\Url\UrlResolverInterface;
use PHPUnit\Framework\TestCase;
use stdClass;

final class AutoInstantiableResolver implements UrlResolverInterface
{
    public static int $instances = 0;

    public function __construct()
    {
        ++self::$instances;
    }

    public function resolve(object $subject, Event $event): iterable
    {
        return ['https://example.com/auto'];
    }
}

final class ArrayResolverLocatorTest extends TestCase
{
    protected function setUp(): void
    {
        AutoInstantiableResolver::$instances = 0;
    }

    public function testRegisteredInstanceIsReturnedAsIs(): void
    {
        $registered = new CallableUrlResolver(static fn(): array => []);
        $locator = new ArrayResolverLocator(['id' => $registered]);

        self::assertSame($registered, $locator->get('id'));
    }

    public function testRegisteredCallableIsWrappedInCallableUrlResolver(): void
    {
        $locator = new ArrayResolverLocator(['id' => static fn(object $s, Event $e): array => ['https://example.com/x']]);

        self::assertSame(['https://example.com/x'], iterator_to_array($locator->get('id')->resolve(new stdClass(), Event::Updated)));
    }

    public function testSetRegistersAResolverAfterConstruction(): void
    {
        $locator = new ArrayResolverLocator();
        $locator->set('id', static fn(): array => ['/late']);

        self::assertSame(['/late'], iterator_to_array($locator->get('id')->resolve(new stdClass(), Event::Updated)));
    }

    public function testFqcnIsAutoinstantiatedAndCached(): void
    {
        $locator = new ArrayResolverLocator();

        $first = $locator->get(AutoInstantiableResolver::class);
        $second = $locator->get(AutoInstantiableResolver::class);

        self::assertSame($first, $second);
        self::assertSame(1, AutoInstantiableResolver::$instances);
    }

    public function testUnknownIdThrowsConfigurationException(): void
    {
        $locator = new ArrayResolverLocator();

        $this->expectException(ConfigurationException::class);
        $locator->get('nope');
    }

    public function testClassNameNotImplementingInterfaceIsTreatedAsUnknown(): void
    {
        $locator = new ArrayResolverLocator();

        $this->expectException(ConfigurationException::class);
        $locator->get(stdClass::class);
    }
}
