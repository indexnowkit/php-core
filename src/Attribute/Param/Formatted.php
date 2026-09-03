<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * DateTimeInterface::format() of the value behind an accessor: new Formatted('publishedAt', 'Y').
 */
final readonly class Formatted implements ParamValue
{
    public function __construct(public string $path, public string $format) {}
}
