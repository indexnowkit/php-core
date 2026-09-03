<?php

declare(strict_types=1);

namespace IndexNowKit\Attribute;

/**
 * Where a {@see UrlRule} gets its URLs from.
 */
enum RuleSource: string
{
    case Route = 'route';
    case Resolver = 'resolver';
    case Via = 'via';
    case Url = 'url';
    case Urls = 'urls';
}
