<?php

namespace App\Libraries\Scanner;

/**
 * Reads package headers only — never executes anything (FR-UC-UPL-07).
 * Detects container format and CPU architecture so an ARM64 binary cannot be
 * uploaded into an x64 slot (FR-UC-PKG-02, METADATA_MISMATCH).
 */
final class BinaryInspector
{
    /**
     * @param string      $head first bytes of the file (≥ 64 KB recommended)
     * @param string|null $tail last 512 bytes (DMG koly trailer), when available
     *
     * @return array{format: string, architectures: list<string>, detail: array<string, mixed>}
     */
    public static function inspect(string $head, ?string $tail = null): array
    {
        $fmt    = 'unknown';
        $arches = [];
        $detail = [];

        if (str_starts_with($head, 'MZ')) {
            $fmt = 'pe';
            $off = strlen($head) >= 64 ? unpack('V', substr($head, 0x3C, 4))[1] : 0;
            if ($off > 0 && $off + 6 <= strlen($head) && substr($head, $off, 4) === "PE\0\0") {
                $machine = unpack('v', substr($head, $off + 4, 2))[1];
                $arches[] = match ($machine) {
                    0x8664  => 'x64',
                    0xAA64  => 'arm64',
                    0x014C  => 'x86',
                    0xA641  => 'arm64', // ARM64EC
                    default => sprintf('0x%04x', $machine),
                };
                $detail['pe_machine'] = sprintf('0x%04x', $machine);
            }
        } elseif (str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
            $fmt = 'msi'; // OLE compound document (MSI); arch lives in the Summary Information stream
            if (preg_match('/(x64|Arm64|Intel64|AMD64|Intel);\d+/', $head, $m)) {
                $arches[] = match (strtolower($m[1])) {
                    'arm64' => 'arm64',
                    'intel' => 'x86',
                    default => 'x64',
                };
            }
        } elseif (in_array(substr($head, 0, 4), ["\xCF\xFA\xED\xFE", "\xCE\xFA\xED\xFE"], true)) {
            $fmt      = 'macho';
            $arches[] = self::machoCpu(unpack('V', substr($head, 4, 4))[1]);
        } elseif (substr($head, 0, 4) === "\xCA\xFE\xBA\xBE") {
            $fmt   = 'macho-fat';
            $count = unpack('N', substr($head, 4, 4))[1];
            for ($i = 0; $i < min($count, 8); $i++) {
                $arches[] = self::machoCpu(unpack('N', substr($head, 8 + $i * 20, 4))[1]);
            }
        } elseif (str_starts_with($head, "\x7FELF")) {
            $fmt       = 'elf';
            $le        = ord($head[5]) === 1;
            $machine   = unpack($le ? 'v' : 'n', substr($head, 18, 2))[1];
            $arches[]  = match ($machine) {
                0x3E    => 'x64',
                0xB7    => 'arm64',
                0x03    => 'x86',
                default => sprintf('0x%x', $machine),
            };
            if (substr($head, 8, 3) === "AI\x02") {
                $fmt = 'appimage';
            }
        } elseif (str_starts_with($head, 'xar!')) {
            $fmt = 'pkg';
        } elseif (str_starts_with($head, "!<arch>\ndebian-binary")) {
            $fmt = 'deb';
            if (preg_match('/Architecture:\s*(\S+)/', $head, $m)) {
                $arches[] = self::normalizeArch($m[1]);
            }
        } elseif (str_starts_with($head, "\xED\xAB\xEE\xDB")) {
            $fmt = 'rpm';
            // Lead: arch number at offset 8 is unreliable; use the name "…x86_64" / "…aarch64" in the lead name.
            $name = rtrim(substr($head, 10, 66), "\0");
            $detail['rpm_name'] = $name;
            if (preg_match('/(x86_64|aarch64|noarch|i686)/', $head, $m)) {
                $arches[] = self::normalizeArch($m[1]);
            }
        } elseif (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
            $fmt = 'zip';
        }

        if ($fmt === 'unknown' && $tail !== null && str_contains($tail, 'koly')) {
            $fmt = 'dmg';
        } elseif ($fmt === 'unknown' && (str_starts_with($head, "x\x01") || str_starts_with($head, "x\xDA") || str_contains(substr($head, 0, 1024), 'koly'))) {
            $fmt = 'dmg';
        }

        return ['format' => $fmt, 'architectures' => array_values(array_unique($arches)), 'detail' => $detail];
    }

    private static function machoCpu(int $cpu): string
    {
        return match ($cpu) {
            0x01000007 => 'x64',
            0x0100000C => 'arm64',
            7          => 'x86',
            default    => sprintf('0x%x', $cpu),
        };
    }

    public static function normalizeArch(string $a): string
    {
        return match (strtolower($a)) {
            'amd64', 'x86_64', 'x64' => 'x64',
            'arm64', 'aarch64'       => 'arm64',
            'i386', 'i686', 'x86'    => 'x86',
            'all', 'noarch'          => 'any',
            default                  => strtolower($a),
        };
    }

    /** Formats acceptable for each package type. */
    public const TYPE_FORMATS = [
        'exe'      => ['pe'],
        'msi'      => ['msi'],
        'dmg'      => ['dmg', 'unknown'], // DMG trailer may be unavailable on non-seekable streams
        'pkg'      => ['pkg'],
        'zip'      => ['zip'],
        'appimage' => ['appimage', 'elf'],
        'deb'      => ['deb'],
        'rpm'      => ['rpm'],
        'apk'      => ['zip'],
        'aab'      => ['zip'],
        'ipa'      => ['zip'],
    ];

    /**
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public static function matches(array $inspection, string $type, string $arch): array
    {
        $formats = self::TYPE_FORMATS[$type] ?? null;
        if ($formats !== null && ! in_array($inspection['format'], $formats, true)) {
            return ['ok' => false, 'code' => 'METADATA_MISMATCH', 'message' => "File is {$inspection['format']}, expected {$type}"];
        }
        $found = $inspection['architectures'];
        if ($found !== [] && ! in_array($arch, ['any', 'universal'], true) && ! in_array($arch, $found, true) && ! in_array('any', $found, true)) {
            return ['ok' => false, 'code' => 'METADATA_MISMATCH', 'message' => 'Binary architecture ' . implode('/', $found) . " does not match {$arch} slot"];
        }

        return ['ok' => true, 'code' => null, 'message' => null];
    }
}
