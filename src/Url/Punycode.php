<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

/**
 * RFC 3492 encoder for host names (label by label). Used when ext-intl is unavailable.
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

    public static function encodeHost(string $host): string
    {
        if (!preg_match('/[^\x00-\x7F]/', $host)) {
            return $host;
        }
        if (\function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii !== false) {
                return $ascii;
            }
        }
        $labels = explode('.', mb_strtolower($host, 'UTF-8'));
        foreach ($labels as $i => $label) {
            if (preg_match('/[^\x00-\x7F]/', $label)) {
                $labels[$i] = 'xn--' . self::encodeLabel($label);
            }
        }

        return implode('.', $labels);
    }

    private static function encodeLabel(string $input): string
    {
        $codePoints = array_map(static fn(string $c) => mb_ord($c, 'UTF-8'), mb_str_split($input, 1, 'UTF-8'));
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
