<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Attribute\ParamExtractor;

/**
 * A URL resolver that reads `params` and `when` with a {@see ParamExtractor}: the facade derives its own extractor from
 * the resolver it was given, so the change handler and the `explain` command read exactly what the resolver reads
 * (tier Implement, docs/bc.md).
 */
interface ParamExtractorAwareInterface
{
    public function extractor(): ParamExtractor;
}
