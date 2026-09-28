<?php

namespace App\Libraries;

/**
 * Prefixed ULIDs used as public identifiers (app_, rel_, pkg_, rev_ …) (§11).
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(?int $timeMs = null): string
    {
        $time = $timeMs ?? (int) floor(microtime(true) * 1000);
        $timeChars = '';
        for ($i = 9; $i >= 0; $i--) {
            $mod       = $time % 32;
            $timeChars = self::ALPHABET[$mod] . $timeChars;
            $time      = intdiv($time, 32);
        }
        $rand  = random_bytes(10);
        $bits  = '';
        foreach (str_split($rand) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $randChars = '';
        foreach (str_split($bits, 5) as $chunk) {
            $randChars .= self::ALPHABET[bindec($chunk)];
        }

        return $timeChars . $randChars;
    }

    public static function prefixed(string $prefix): string
    {
        return $prefix . '_' . self::generate();
    }
}
