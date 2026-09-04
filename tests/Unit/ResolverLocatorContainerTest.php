<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit;

use IndexNowKit\Event;
use IndexNowKit\Exception\ConfigurationException;
use IndexNowKit\Url\ArrayResolverLocator;
use IndexNowKit\Url\CallableUrlResolver;
use IndexNowKit\Url\UrlResolverInterface;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use stdClass;

final class NeedsArgumentsResolver implements UrlResolverInterface
{
    public function __construct(public string $prefix) {}

    public function resolve(object $subject, Event $event): iterable
    {
        return [$this->prefix];
    }
}

/**
 * ArrayResolverLocator with the adapter's container lookup (`locate:`): what the three ContainerResolverLocator
 * classes of the adapters did, with the error texts in one place.
 */
final class ResolverLocatorContainerTest extends TestCase
{
    #[TestDox('registered first, then the container, then an instantiable class; a located resolver is memoised')]
    public function testLookupOrder(): void
    {
        $registered = new CallableUrlResolver(static fn(): string => '/registered');
        $container = ['svc' => new CallableUrlResolver(static fn(): string => '/container')];
        $calls = 0;
        $locator = new ArrayResolverLocator(['svc' => $registered], locate: static function (string $id) use ($container, &$calls): ?object {
            ++$calls;

            return $container[$id] ?? null;
        }, hint: 'a service id');

        self::assertSame($registered, $locator->get('svc'), 'registered wins over the container');
        self::assertSame(0, $calls);

        $located = new ArrayResolverLocator([], locate: static function (string $id) use ($container, &$calls): ?object {
            ++$calls;

            return $container[$id] ?? null;
        }, hint: 'a service id');
        self::assertSame($container['svc'], $located->get('svc'));
        self::assertSame($container['svc'], $located->get('svc'));
        self::assertSame(1, $calls, 'located once');
        self::assertInstanceOf(NeedsArgumentsResolver::class, (new ArrayResolverLocator(['x' => new NeedsArgumentsResolver('/x')]))->get('x'));
    }

    #[TestDox('a located object that is no resolver, a class with dependencies the container does not know, and an unknown id name the hint')]
    public function testErrors(): void
    {
        $locator = new ArrayResolverLocator([], locate: static fn(string $id): ?object => $id === 'thing' ? new stdClass() : null, hint: 'a container binding');
        try {
            $locator->get('thing');
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('IndexNow URL resolver "thing" resolves to stdClass, which does not implement', $e->getMessage());
        }
        try {
            $locator->get(NeedsArgumentsResolver::class);
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('has constructor dependencies but is not known to the container under that id; register it as a container binding', $e->getMessage());
        }
        try {
            $locator->get('missing');
            self::fail('expected an exception');
        } catch (ConfigurationException $e) {
            self::assertSame('IndexNow URL resolver "missing" is neither a container binding nor an instantiable class. Implement ' . UrlResolverInterface::class . ' and reference the class or its id.', $e->getMessage());
        }
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No IndexNow URL resolver registered for "missing".');
        (new ArrayResolverLocator())->get('missing');
    }
}
