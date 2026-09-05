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
     * @throws InvalidArgumentException when no class of that name is autoloadable (the namespaces that were tried are
     *                                  named) or the class is not one the ORM manages
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
            $where = $this->namespaces === [] ? 'as given' : \sprintf('as given and under %s', implode(', ', array_map(static fn(string $ns): string => rtrim($ns, '\\') . '\\', $this->namespaces)));
            throw new InvalidArgumentException(\sprintf('Class "%s" not found (looked %s). Give the fully qualified name of %s, e.g. App\\Entity\\Post.', $class, $where, $this->expected));
        }
        if (!($this->accepts)($candidate)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not %s: the command loads objects by id through the ORM and resolves their URLs from #[IndexNow] rules, so it needs a class the ORM manages.', $candidate, $this->expected));
        }

        return $candidate;
    }
}
