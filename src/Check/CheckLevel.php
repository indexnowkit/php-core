<?php

declare(strict_types=1);

namespace IndexNowKit\Check;

enum CheckLevel: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
}
