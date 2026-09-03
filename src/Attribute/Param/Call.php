<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * A method call with arguments: new Call('slugFor', Placeholder::Locale). Placeholders are replaced per
 * generated URL, other arguments are passed as given.
 */
final readonly class Call implements ParamValue
{
    /** @var list<mixed> */
    public array $args;

    public function __construct(public string $method, mixed ...$args)
    {
        $this->args = array_values($args);
    }
}
