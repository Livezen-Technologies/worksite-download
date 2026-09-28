<?php

namespace App\Libraries;

/**
 * Version of the Update Center itself. /VERSION is the single source of
 * truth (managed by scripts/version.php); CI/Docker add the build number and
 * commit through UC_BUILD / UC_COMMIT.
 */
final class AppVersion
{
    private static ?string $version = null;

    public static function current(): string
    {
        if (self::$version === null) {
            $v = getenv('UC_VERSION') ?: null;
            foreach ([ROOTPATH . '../VERSION', ROOTPATH . 'VERSION'] as $file) {
                if ($v === null && is_file($file)) {
                    $v = trim((string) file_get_contents($file));
                }
            }
            self::$version = $v !== null && Semver::isValid($v) ? $v : '0.0.0-dev';
        }

        return self::$version;
    }

    /** @return array{version: string, build: ?string, commit: ?string} */
    public static function info(): array
    {
        return [
            'version' => self::current(),
            'build'   => getenv('UC_BUILD') ?: null,
            'commit'  => getenv('UC_COMMIT') ? substr((string) getenv('UC_COMMIT'), 0, 12) : null,
        ];
    }
}
