<?php

declare(strict_types=1);

namespace IndexNowKit\Sitemap;

/**
 * Where {@see SitemapReader} keeps a document while parsing it.
 */
enum SpoolMode: string
{
    /** A temp file when the temp directory is writable, otherwise memory (logged once as a warning). */
    case Auto = 'auto';

    /** Always a temp file; fail when it cannot be created (read-only filesystem). */
    case Disk = 'disk';

    /** Always memory: one copy of the document, bounded by the reader's size cap. */
    case Memory = 'memory';
}
