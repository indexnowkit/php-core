<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * Typed value source usable in #[IndexNow(params: [...])] and #[IndexNow(host: ...)] next to plain accessor
 * strings. Closed set: {@see Accessor}, {@see Value}, {@see Formatted}, {@see Call}. Anything else is a resolver.
 */
interface ParamValue {}
