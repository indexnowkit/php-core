<?php

declare(strict_types=1);

namespace IndexNowKit\Console;

use IndexNowKit\Event;
use IndexNowKit\Exception\InvalidArgumentException;

/**
 * How `submit-<subject>` and `explain` find the objects named on the command line. Each ORM adapter ships one
 * (Doctrine repositories, Eloquent queries); an application decorates it to honour soft deletes, tenant scoping or a
 * different id format.
 */
interface SubjectLoaderInterface
{
    /**
     * Turns the class argument (an FQCN, or a short name under the framework's default namespace) into a class the
     * ORM manages.
     *
     * @return class-string
     *
     * @throws InvalidArgumentException when the class is unknown or not managed by the ORM
     */
    public function resolveClass(string $class): string;

    /**
     * @param class-string $class
     * @param list<string> $ids
     * @param Event        $event the event the objects are submitted for: a deleted-event lookup may include
     *                            soft-deleted rows
     *
     * @return array{0: list<object>, 1: list<string>} found objects and missing ids
     */
    public function byIds(string $class, array $ids, Event $event): array;

    /**
     * @param class-string $class
     *
     * @return iterable<object> at most $limit objects
     */
    public function all(string $class, int $limit, Event $event): iterable;
}
