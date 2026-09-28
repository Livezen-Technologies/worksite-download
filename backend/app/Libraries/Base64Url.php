<?php

namespace App\Libraries;

final class Base64Url
{
    public static function encode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function decode(string $s): string
    {
        $s   = strtr($s, '-_', '+/');
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode($s, true);

        return $out === false ? '' : $out;
    }
}
