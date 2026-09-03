<?php

declare(strict_types=1);

namespace IndexNowKit\Key;

use IndexNowKit\Exception\InvalidArgumentException;

/**
 * Cryptographically random keys (CSPRNG). Default: 32 hex characters = 128 bits of entropy.
 */
final class KeyGenerator
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    private function __construct() {}

    /**
     * @param bool $hex hex digits only (the documented default); false uses the full [A-Za-z0-9] alphabet
     *
     * @throws InvalidArgumentException when $length is outside 8..128
     */
    public static function generate(int $length = 32, bool $hex = true): string
    {
        if ($length < KeyValidator::MIN_LENGTH || $length > KeyValidator::MAX_LENGTH) {
            throw new InvalidArgumentException(\sprintf('Key length must be between %d and %d.', KeyValidator::MIN_LENGTH, KeyValidator::MAX_LENGTH));
        }
        if ($hex) {
            return substr(bin2hex(random_bytes(max(1, (int) ceil($length / 2)))), 0, $length);
        }
        $key = '';
        $max = \strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; ++$i) {
            $key .= self::ALPHABET[random_int(0, $max)];
        }

        return $key;
    }
}
