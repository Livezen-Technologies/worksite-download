<?php

namespace App\Libraries;

/**
 * Deterministic JSON used for everything that is hashed or signed:
 * object keys sorted recursively, no insignificant whitespace, slashes and
 * unicode unescaped. Lists keep their order.
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public static function sort(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
            if ($value === []) {
                return new \stdClass();
            }
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'sort'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $k => $v) {
            $value[$k] = self::sort($v);
        }

        return $value;
    }
}
