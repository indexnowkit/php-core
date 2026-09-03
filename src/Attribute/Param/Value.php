<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * A constant: #[IndexNow(route: 'post_show', params: ['_format' => new Value('html')])].
 */
final readonly class Value implements ParamValue
{
    public function __construct(public mixed $value) {}
}
