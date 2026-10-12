<?php

namespace App\Services;

/**
 * A Sparkle appcast for an application that updates on macOS through Sparkle rather than through the
 * Update API — WorkSuite Office, whose updater is Sparkle 2:
 *
 *   GET /api/v1/apps/{app}/channels/{channel}/appcast.xml?arch=arm64|x64[&type=dmg|zip]
 *
 * WHAT IT OFFERS. Exactly what an anonymous macOS update check on that channel is offered
 * (UpdateResolverService::decide): the newest published release that is fully rolled out (a staged release
 * is not, until it reaches 100 %: a feed has no device to put in a cohort), not blocked, with a READY
 * package for the architecture. One item at most; Sparkle installs the newest item it may.
 *
 * THE SIGNATURE COMES FROM CI. Sparkle checks an Ed25519 signature over the archive itself against the key
 * the app carries (SUPublicEDKey). The signing service signs documents, never archives, so the publish
 * script signs each macOS archive with the application's Sparkle key and records the signature in the CI
 * build attestation beside the file's SHA-256 (`files[].sparkle_ed_signature`). The appcast takes it from a
 * verified attestation of the release, matched on the active revision's SHA-256 — so an emergency
 * replacement needs a new attestation, as it already does for the release manifest. A package without one
 * is left out: Sparkle would refuse it.
 *
 * VERSIONS. `sparkle:version` is the release's build number, which the build stamps into CFBundleVersion
 * and Sparkle compares; `sparkle:shortVersionString` is the version people see.
 */
class SparkleAppcastService
{
    public const ARCHES = ['arm64', 'x64'];

    /** Archives Sparkle installs by replacing the app; installer packages (pkg) are not offered. */
    public const TYPES = ['dmg', 'zip'];

    public function build(array $app, array $channel, string $arch, ?string $type = null): string
    {
        $base = rtrim(config('UpdateCenter')->publicBaseUrl, '/');
        $x    = static fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $code = strtoupper((string) $channel['code']);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:sparkle="http://www.andymatuschak.org/xml-namespaces/sparkle"><channel>'
            . '<title>' . $x($app['name']) . '</title>'
            . '<link>' . $x($base . '/apps/' . $app['slug']) . '</link>'
            . '<description>' . $x($app['name'] . ' updates — ' . $channel['name'] . ', macOS ' . $arch) . '</description>'
            . '<language>en</language>';

        $offer = $this->offer($app, $channel, $arch, $type);
        if ($offer !== null) {
            [$release, $pkg, $signature] = $offer;
            $notes = ChangelogService::present((new ChangelogService())->get((int) $release['id']));
            $html  = ($notes['summary'] ? '<p>' . htmlspecialchars((string) $notes['summary'], ENT_QUOTES, 'UTF-8') . '</p>' : '') . $notes['html'];
            $url   = $base . '/download/' . $app['slug'] . '/' . ($code === 'STABLE' ? '' : strtolower($code) . '/')
                . $release['version'] . '/macos/' . $arch . '/' . $pkg['package_type'];

            $xml .= '<item>'
                . '<title>' . $x('Version ' . $release['version']) . '</title>'
                . '<link>' . $x($base . '/apps/' . $app['slug'] . '/releases/' . $release['version']) . '</link>'
                . '<pubDate>' . gmdate(DATE_RSS, strtotime((string) ($release['published_at'] ?? 'now') . ' UTC')) . '</pubDate>'
                . '<sparkle:version>' . (int) $release['build_number'] . '</sparkle:version>'
                . '<sparkle:shortVersionString>' . $x($release['version']) . '</sparkle:shortVersionString>'
                . ($pkg['minimum_os_version'] ? '<sparkle:minimumSystemVersion>' . $x($pkg['minimum_os_version']) . '</sparkle:minimumSystemVersion>' : '')
                . ($this->critical($release) ? '<sparkle:criticalUpdate></sparkle:criticalUpdate>' : '')
                . '<description>' . $x($html) . '</description>'
                . '<enclosure url="' . $x($url) . '" length="' . (int) $pkg['size_bytes'] . '" type="application/octet-stream" sparkle:edSignature="' . $x($signature) . '"/>'
                . '</item>';
        }

        return $xml . '</channel></rss>';
    }

    /**
     * The release, package and Sparkle signature to offer, or null.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}|null
     */
    private function offer(array $app, array $channel, string $arch, ?string $type): ?array
    {
        // As an anonymous device that has nothing yet: the release the Update API would offer anybody.
        $d = (new UpdateResolverService())->decide($app, $channel, '0.0.0', 'macos', $arch, $type, null, null, null);
        if (! $d['offered_id'] || ! $d['package_id']) {
            return null;
        }

        $pkg = db_connect()->table('release_packages p')->select('p.*, r.size_bytes, r.sha256')
            ->join('release_package_revisions r', 'r.id = p.active_revision_id')->where('p.id', $d['package_id'])->get()->getRowArray();
        if ($pkg === null || ! in_array($pkg['package_type'], self::TYPES, true)) {
            return null;
        }

        $release   = Catalog::releaseById((int) $d['offered_id']);
        $signature = $this->signature((int) $release['id'], (string) $pkg['sha256']);

        return $signature === null ? null : [$release, $pkg, $signature];
    }

    /**
     * Whether Sparkle should treat it as critical (no "Skip This Version"): when the Update API answers
     * UPDATE_REQUIRED for it — CRITICAL, or mandatory once its deadline has passed.
     */
    private function critical(array $release): bool
    {
        if ($release['release_type'] === 'CRITICAL') {
            return true;
        }
        $mandatory = $release['release_type'] === 'MANDATORY' || (bool) $release['mandatory'];

        return $mandatory && $release['mandatory_after'] !== null && strtotime($release['mandatory_after'] . ' UTC') <= time();
    }

    /** The Sparkle signature CI attested for these bytes: base64 of 64 bytes, from the newest verified attestation naming them. */
    private function signature(int $releaseId, string $sha256): ?string
    {
        $rows = db_connect()->table('build_attestations')->select('payload')->where('release_id', $releaseId)->where('verified', 1)
            ->orderBy('id', 'DESC')->get()->getResultArray();
        foreach ($rows as $row) {
            foreach ((array) (json_decode((string) $row['payload'], true)['files'] ?? []) as $f) {
                if (! is_array($f) || strtolower((string) ($f['sha256'] ?? '')) !== strtolower($sha256)) {
                    continue;
                }
                $sig = (string) ($f['sparkle_ed_signature'] ?? '');
                $raw = base64_decode($sig, true);
                if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_BYTES) {
                    return $sig;
                }
            }
        }

        return null;
    }
}
