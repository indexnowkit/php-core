<?php

declare(strict_types=1);

namespace IndexNowKit;

/**
 * Lifecycle event of a persisted object that may change its public URL(s).
 */
enum Event: string
{
    case Created = 'created';
    case Updated = 'updated';
    /** Also used when an object leaves the published state (`when` turned false). */
    case Deleted = 'deleted';
}
