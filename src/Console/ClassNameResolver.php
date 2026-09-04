<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use Closure;
use IndexNowKit\Exception\InvalidArgumentException;

/**
 * The class argument of `submit-<subject>` and `explain`: an FQCN, or a short name looked up in the framework's
 * default namespaces (`App\Entity`, `App\Models`, `app\models`), checked to be something the ORM manages. The
 * `resolveClass()` of every {@see SubjectLoaderInterface} delegates here, so the two error texts exist once.
 */
final class ClassNameResolver
{
    /**
     * @param list<string>                 $namespaces namespaces a short class name is looked up in, in order
     * @param Closure(class-string): bool  $accepts    whether a class the ORM manages (`is_subclass_of($class, Model::class)`)
     * @param string                       $expected   what an accepted class is, for the error ("an Eloquent model", "a managed Doctrine entity")
     */
    public function __construct(
        private readonly array $namespaces,
        private readonly Closure $accepts,
        private readonly string $expected,
    ) {}

    /**
     * @return class-string
     *
     * @throws InvalidArgumentException `Class "%s" not found.` or `"%s" is not %s.`
     */
    public function resolve(string $class): string
    {
        $candidate = ltrim($class, '\\');
        if (!class_exists($candidate)) {
            foreach ($this->namespaces as $namespace) {
                $qualified = rtrim($namespace, '\\') . '\\' . $candidate;
                if (class_exists($qualified)) {
                    $candidate = $qualified;
                    break;
                }
            }
        }
        if (!class_exists($candidate)) {
            throw new InvalidArgumentException(\sprintf('Class "%s" not found.', $class));
        }
        if (!($this->accepts)($candidate)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not %s.', $candidate, $this->expected));
        }

        return $candidate;
    }
}
