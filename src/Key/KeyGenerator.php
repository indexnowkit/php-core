<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

use InvalidArgumentException;

final class KeyGenerator
{
    public static function generate(int $length = 32): string
    {
        if ($length < 8 || $length > 128) {
            throw new InvalidArgumentException('Key length must be between 8 and 128.');
        }

        return substr(bin2hex(random_bytes(max(1, (int) ceil($length / 2)))), 0, $length);
    }
}
