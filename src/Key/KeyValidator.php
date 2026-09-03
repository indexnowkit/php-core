<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

use IndexNowKit\Exception\ConfigurationException;

final class KeyValidator
{
    public const PATTERN = '/^[A-Za-z0-9-]{8,128}$/';

    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }

    public static function assertValid(string $key): void
    {
        if (!self::isValid($key)) {
            throw new ConfigurationException(\sprintf('IndexNow key "%s" is invalid: 8-128 characters from [A-Za-z0-9-] required.', self::mask($key)));
        }
    }

    /** Keys are public (served at /{key}.txt) but must not be logged verbatim. */
    public static function mask(string $key): string
    {
        return \strlen($key) <= 4 ? str_repeat('*', \strlen($key)) : substr($key, 0, 4) . str_repeat('*', min(8, \strlen($key) - 4));
    }
}
