<?php

declare(strict_types=1);

namespace IndexNowKit\Url;

use IndexNowKit\Exception\ConfigurationException;

/**
 * Reads attribute "params" values from an object: property, getter, is/has-method, or "self".
 */
final class ParamExtractor
{
    public const SELF = 'self';

    /**
     * @param array<string, string> $params routeParam => accessor
     * @return array<string, mixed>
     */
    public static function extract(object $subject, array $params): array
    {
        $out = [];
        foreach ($params as $name => $accessor) {
            $out[$name] = self::read($subject, $accessor);
        }

        return $out;
    }

    public static function read(object $subject, string $accessor): mixed
    {
        if ($accessor === self::SELF) {
            return $subject;
        }
        if (str_contains($accessor, '.')) {
            $value = $subject;
            foreach (explode('.', $accessor) as $segment) {
                if (!\is_object($value)) {
                    throw new ConfigurationException(\sprintf('Cannot read "%s" on %s: "%s" is not an object.', $accessor, $subject::class, $segment));
                }
                $value = self::read($value, $segment);
            }

            return $value;
        }
        $ucfirst = ucfirst($accessor);
        foreach ([$accessor, 'get' . $ucfirst, 'is' . $ucfirst, 'has' . $ucfirst] as $method) {
            if (method_exists($subject, $method)) {
                return $subject->$method();
            }
        }
        if (property_exists($subject, $accessor)) {
            return (fn() => $this->$accessor)->call($subject);
        }

        throw new ConfigurationException(\sprintf('Cannot read "%s" on %s: no property, getter or method found.', $accessor, $subject::class));
    }
}
