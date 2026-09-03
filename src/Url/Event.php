<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

enum Event: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
