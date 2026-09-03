<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

use IndexNowKit\Exception\ConfigurationException;

/**
 * IndexNow key rules: 8-128 characters from [A-Za-z0-9-].
 */
final class KeyValidator
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;
    public const PATTERN = '/^[A-Za-z0-9-]{8,128}$/';

    private function __construct() {}

    public static function isValid(string $key): bool
    {
        return preg_match(self::PATTERN, $key) === 1;
    }

    /**
     * @throws ConfigurationException
     */
    public static function assertValid(string $key): void
    {
        if (!self::isValid($key)) {
            throw new ConfigurationException(\sprintf('IndexNow key "%s" is invalid: %d-%d characters from [A-Za-z0-9-] required.', self::mask($key), self::MIN_LENGTH, self::MAX_LENGTH));
        }
    }

    /**
     * Keys are public (served at /{key}.txt) but must not be logged verbatim: first 4 characters, rest masked.
     */
    public static function mask(string $key): string
    {
        return \strlen($key) <= 4 ? str_repeat('*', \strlen($key)) : substr($key, 0, 4) . str_repeat('*', min(8, \strlen($key) - 4));
    }
}
