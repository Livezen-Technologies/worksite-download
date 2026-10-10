<?php

namespace Tests\Support;

/**
 * Tiny, header-accurate package fixtures (never executable in practice).
 */
final class Fixtures
{
    public static function pe(string $arch = 'x64', int $size = 4096): string
    {
        $machine = match ($arch) { 'arm64' => 0xAA64, 'x86' => 0x014C, default => 0x8664 };
        $b       = 'MZ' . str_repeat("\0", 0x3A) . pack('V', 0x80) . str_repeat("\0", 0x80 - 0x40) . "PE\0\0" . pack('v', $machine);

        return $b . random_bytes(max(0, $size - strlen($b)));
    }

    public static function dmg(int $size = 4096): string
    {
        return random_bytes($size - 512) . 'koly' . str_repeat("\0", 508);
    }

    public static function appImage(string $arch = 'x64', int $size = 4096): string
    {
        $h = "\x7FELF\x02\x01\x01\x00AI\x02" . str_repeat("\0", 5) . pack('v', 2) . pack('v', $arch === 'arm64' ? 0xB7 : 0x3E);

        return $h . random_bytes($size - strlen($h));
    }

    public static function machO(string $arch = 'arm64', int $size = 4096): string
    {
        $h = "\xCF\xFA\xED\xFE" . pack('V', $arch === 'arm64' ? 0x0100000C : 0x01000007);

        return $h . random_bytes($size - strlen($h));
    }

    /**
     * Build a server edition ZIP per §17.5.
     *
     * @param array<string, string> $extraFiles path => content
     * @param array<string, mixed>  $pkgOverrides
     */
    public static function serverZip(string $path, string $version, int $build, string $edition, array $modules, array $extraFiles = [], array $pkgOverrides = [], ?callable $mutate = null): string
    {
        $files = ['spark' => "#!/usr/bin/env php\n<?php // spark\n", 'public/index.php' => "<?php // front controller\n", 'web/dist/index.html' => '<!doctype html><title>WorkSuite</title>'];
        foreach ($modules as $m) {
            $files['app/Modules/' . ucfirst($m) . '/Config/Routes.php'] = "<?php // {$m}\n";
        }
        $files += $extraFiles;
        $list = '';
        foreach ($files as $p => $c) {
            $list .= hash('sha256', $c) . '  ' . $p . "\n";
        }
        $pkg = array_merge([
            'schema' => 1, 'product' => 'worksuite-server', 'version' => $version, 'build' => $build, 'edition' => strtolower($edition),
            'scope' => $edition === 'ALL' ? 'core-patch' : 'full', 'min_from_version' => '3.2.0', 'channel' => 'stable',
            'requires' => ['php' => '>=8.2 <8.5', 'db' => 'mariadb>=10.6|mysql>=8.0', 'ext' => ['intl', 'sodium'], 'disk_mb' => 1200],
            'modules' => $modules, 'migrations' => ['count' => 3, 'one_way' => []], 'removed_files' => [], 'post_update' => ['cache:clear'],
            'desktop_compat' => '2.6.0 - 2.8.x', 'files_digest' => 'sha256:' . hash('sha256', $list),
        ], $pkgOverrides);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $p => $c) {
            $zip->addFromString($p, $c);
        }
        $zip->addFromString('files.sha256', $list);
        $zip->addFromString('worksuite-package.json', json_encode($pkg));
        if ($mutate) {
            $mutate($zip);
        }
        $zip->close();

        return $path;
    }
}
