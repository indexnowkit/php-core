<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * Condition for `when`: the value behind an accessor equals a constant (loosely for BackedEnum: the enum or its
 * backing value). For models whose "published" state is a string or an enum rather than a bool:
 *
 * #[IndexNow(route: 'post_show', params: ['slug' => 'slug'], when: new Equals('status', 'published'))]
 */
final readonly class Equals implements ParamValue
{
    public function __construct(public string $path, public mixed $value) {}
}
