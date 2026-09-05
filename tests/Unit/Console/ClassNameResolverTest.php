<?php

declare(strict_types=1);

namespace IndexNowKit\Tests\Unit\Console;

use IndexNowKit\Console\ClassNameResolver;
use IndexNowKit\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ClassNameResolverTest extends TestCase
{
    private static function resolver(): ClassNameResolver
    {
        return new ClassNameResolver(['Nope\\Models', 'IndexNowKit\\Tests\\Unit\\Console'], static fn(string $class): bool => is_subclass_of($class, TestCase::class), 'a test case');
    }

    #[TestDox('an FQCN (with or without a leading backslash) and a short name under the namespaces resolve; the accepted class is returned qualified')]
    public function testResolves(): void
    {
        self::assertSame(self::class, self::resolver()->resolve(self::class));
        self::assertSame(self::class, self::resolver()->resolve('\\' . self::class));
        self::assertSame(self::class, self::resolver()->resolve('ClassNameResolverTest'), 'the first namespace that has the class wins');
    }

    #[TestDox('an unknown class and a class the ORM does not manage have one error text each')]
    public function testErrors(): void
    {
        try {
            self::resolver()->resolve('Nope');
            self::fail('expected an exception');
        } catch (InvalidArgumentException $e) {
            self::assertStringStartsWith('Class "Nope" not found (looked as given and under ', $e->getMessage());
            self::assertStringContainsString('Give the fully qualified name of a test case', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"stdClass" is not a test case: the command loads objects by id through the ORM');
        self::resolver()->resolve(stdClass::class);
    }
}
