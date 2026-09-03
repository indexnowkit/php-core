<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Event;

final class CallableUrlResolver implements UrlResolverInterface
{
    /** @var callable(object, Event): (iterable<string>|string|null) */
    private $callable;

    /**
     * @param callable(object, Event): (iterable<string>|string|null) $callable
     */
    public function __construct(callable $callable)
    {
        $this->callable = $callable;
    }

    public function resolve(object $subject, Event $event) : iterable
    {
        $result = ($this->callable)($subject, $event);
        if ($result === null) {
            return [];
        }

        return \is_string($result) ? [$result] : $result;
    }
}
