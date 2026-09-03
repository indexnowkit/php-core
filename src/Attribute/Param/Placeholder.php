<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute\Param;

/**
 * Argument placeholders of {@see Call}, substituted for every generated URL.
 */
enum Placeholder
{
    /** The locale of the URL being generated (null when the rule has no locale dimension). */
    case Locale;
    /** The host the rule pinned via `host:` (null otherwise). */
    case Host;
}
