<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Exception\InvalidUrlException;

/**
 * IDN host to ASCII: ext-intl (UTS #46) when available, otherwise a plain RFC 3492 encoder.
 * Input is bounded (host <= 253 chars, label <= 63 code points) so the O(n²) encoder stays cheap.
 *
 * @internal
 */
final class Punycode
{
    private const BASE = 36;
    private const TMIN = 1;
    private const TMAX = 26;
    private const SKEW = 38;
    private const DAMP = 700;
    private const INITIAL_BIAS = 72;
    private const INITIAL_N = 128;

    private function __construct() {}

    /**
     * @throws InvalidUrlException
     */
    public static function encodeHost(string $host): string
    {
        if (preg_match('/[^\x00-\x7F]/', $host) !== 1) {
            return $host;
        }
        if (preg_match('//u', $host) !== 1) {
            throw new InvalidUrlException('Host name is not valid UTF-8.');
        }
        if (\strlen($host) > 4 * UrlNormalizer::MAX_HOST_LENGTH) {
            throw new InvalidUrlException(\sprintf('Host name longer than %d characters.', UrlNormalizer::MAX_HOST_LENGTH));
        }
        if (\function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new InvalidUrlException(\sprintf('Host name "%s" is not a valid IDN.', $host));
            }

            return $ascii;
        }
        $labels = explode('.', \function_exists('mb_strtolower') ? mb_strtolower($host, 'UTF-8') : $host);
        foreach ($labels as $i => $label) {
            if (preg_match('/[^\x00-\x7F]/', $label) === 1) {
                $labels[$i] = 'xn--' . self::encodeLabel($label);
            }
        }

        return implode('.', $labels);
    }

    /**
     * @throws InvalidUrlException
     */
    private static function encodeLabel(string $input): string
    {
        $codePoints = self::codePoints($input);
        if (\count($codePoints) > UrlNormalizer::MAX_LABEL_LENGTH) {
            throw new InvalidUrlException(\sprintf('Host label longer than %d characters.', UrlNormalizer::MAX_LABEL_LENGTH));
        }
        $n = self::INITIAL_N;
        $delta = 0;
        $bias = self::INITIAL_BIAS;
        $output = '';
        foreach ($codePoints as $cp) {
            if ($cp < 0x80) {
                $output .= \chr($cp);
            }
        }
        $b = $h = \strlen($output);
        if ($b > 0) {
            $output .= '-';
        }
        $len = \count($codePoints);
        while ($h < $len) {
            $m = PHP_INT_MAX;
            foreach ($codePoints as $cp) {
                if ($cp >= $n && $cp < $m) {
                    $m = $cp;
                }
            }
            $delta += ($m - $n) * ($h + 1);
            $n = $m;
            foreach ($codePoints as $cp) {
                if ($cp < $n) {
                    ++$delta;
                }
                if ($cp === $n) {
                    $q = $delta;
                    for ($k = self::BASE; ; $k += self::BASE) {
                        $t = $k <= $bias ? self::TMIN : ($k >= $bias + self::TMAX ? self::TMAX : $k - $bias);
                        if ($q < $t) {
                            break;
                        }
                        $output .= self::digit($t + ($q - $t) % (self::BASE - $t));
                        $q = intdiv($q - $t, self::BASE - $t);
                    }
                    $output .= self::digit($q);
                    $bias = self::adapt($delta, $h + 1, $h === $b);
                    $delta = 0;
                    ++$h;
                }
            }
            ++$delta;
            ++$n;
        }

        return $output;
    }

    /**
     * Unicode code points of a UTF-8 string, lower-cased for ASCII letters (no mbstring dependency).
     *
     * @return list<int>
     */
    private static function codePoints(string $input): array
    {
        $chars = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw new InvalidUrlException('Host name is not valid UTF-8.');
        }
        $points = [];
        foreach ($chars as $char) {
            $bytes = array_values(unpack('C*', $char) ?: []);
            $points[] = match (\count($bytes)) {
                1 => $bytes[0] >= 0x41 && $bytes[0] <= 0x5A ? $bytes[0] + 0x20 : $bytes[0],
                2 => (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F),
                3 => (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F),
                default => (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12) | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F),
            };
        }

        return $points;
    }

    private static function adapt(int $delta, int $numPoints, bool $firstTime): int
    {
        $delta = $firstTime ? intdiv($delta, self::DAMP) : intdiv($delta, 2);
        $delta += intdiv($delta, $numPoints);
        $k = 0;
        while ($delta > intdiv((self::BASE - self::TMIN) * self::TMAX, 2)) {
            $delta = intdiv($delta, self::BASE - self::TMIN);
            $k += self::BASE;
        }

        return $k + intdiv((self::BASE - self::TMIN + 1) * $delta, $delta + self::SKEW);
    }

    private static function digit(int $d): string
    {
        return $d < 26 ? \chr(97 + $d) : \chr(22 + $d);
    }
}
