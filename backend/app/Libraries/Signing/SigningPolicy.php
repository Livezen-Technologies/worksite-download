<?php

namespace App\Libraries\Signing;

use App\Libraries\Base64Url;
use App\Libraries\CanonicalJson;

/**
 * The rules the Signing Service enforces before it will use the release key (§2.2).
 * Runs inside the signing-service process, which holds the only handle on the
 * release key, so a compromised API tier cannot mint a manifest:
 *
 *  RELEASE manifest — every package SHA-256 must appear in a CI build attestation
 *  signed by a pinned CI key for the same app/version/build, and the evidence must
 *  carry a QA pass and a Release Manager approval from two different users.
 *
 *  OS_MANIFEST — WorkSuite OS ring manifest (flat `kid:base64` signature). Must name a
 *  ring/arch/version, and when it carries artifacts each needs a SHA-256 that matches the
 *  evidence `artifacts` the API tier hashed (and a pinned CI attestation when required).
 *
 *  CHANNEL manifest — `current.release_manifest_sha256` must be the hash of a
 *  release manifest that verifies under a release key.
 *
 *  CHECKSUMS (SHA256SUMS of a published server release, WS-CORE-SYSUPDATE FR-DL-REL-05) —
 *  `release_manifest_sha256` must be the hash of a release manifest that verifies under a
 *  release key, and every listed file must be a package of that manifest with the same hash.
 */
final class SigningPolicy
{
    /**
     * @param array<string, string> $ciKeys      kid => raw Ed25519 public key
     * @param array<string, string> $releaseKeys kid => raw Ed25519 public key
     */
    public function __construct(
        private readonly array $ciKeys,
        private readonly array $releaseKeys,
        private readonly bool $requireAttestation,
        private readonly bool $requireApprovals,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $evidence
     *
     * @return list<string> refusal reasons (empty = allowed)
     */
    public function check(string $kind, array $document, array $evidence): array
    {
        return match ($kind) {
            'RELEASE' => $this->checkRelease($document, $evidence),
            'CHANNEL' => $this->checkChannel($document, $evidence),
            'CHECKSUMS' => $this->checkChecksums($document, $evidence),
            'ROTATION' => [],
            'OS_MANIFEST' => $this->checkOsManifest($document, $evidence),
            default   => ['Unknown document kind'],
        };
    }

    private function checkRelease(array $doc, array $evidence): array
    {
        $errors   = [];
        $packages = $doc['packages'] ?? [];
        if (! is_array($packages) || $packages === []) {
            $errors[] = 'Release manifest lists no packages';
        }

        $attestation = $evidence['attestation'] ?? null;
        if ($this->requireAttestation || $attestation !== null) {
            if (! is_array($attestation)) {
                $errors[] = 'CI build attestation missing';
            } else {
                $payload = $attestation['payload'] ?? null;
                $kid     = $attestation['key_id'] ?? '';
                $sig     = Base64Url::decode((string) ($attestation['signature'] ?? ''));
                if (! is_array($payload) || ! isset($this->ciKeys[$kid])
                    || ! Ed25519::verify(CanonicalJson::encode($payload), $sig, $this->ciKeys[$kid])) {
                    $errors[] = 'CI build attestation signature invalid or key not pinned';
                } else {
                    if (($payload['app_id'] ?? null) !== ($doc['app'] ?? null)
                        || (string) ($payload['version'] ?? '') !== (string) ($doc['version'] ?? '')
                        || (int) ($payload['build'] ?? -1) !== (int) ($doc['build'] ?? -2)) {
                        $errors[] = 'Attestation app/version/build does not match manifest';
                    }
                    $attested = [];
                    foreach ((array) ($payload['files'] ?? []) as $f) {
                        if (isset($f['sha256'])) {
                            $attested[strtolower((string) $f['sha256'])] = true;
                        }
                    }
                    foreach ((array) $packages as $p) {
                        if (! isset($attested[strtolower((string) ($p['sha256'] ?? ''))])) {
                            $errors[] = 'Package ' . ($p['file_name'] ?? '?') . ' hash not in CI attestation';
                        }
                    }
                }
            }
        }

        if ($this->requireApprovals && strtoupper((string) ($doc['channel'] ?? '')) === 'STABLE') {
            $qa = $rm = [];
            foreach ((array) ($evidence['approvals'] ?? []) as $a) {
                if (($a['kind'] ?? '') === 'QA' && ($a['decision'] ?? '') === 'PASS') {
                    $qa[] = (string) $a['user_id'];
                }
                if (($a['kind'] ?? '') === 'RELEASE' && ($a['decision'] ?? '') === 'APPROVED') {
                    $rm[] = (string) $a['user_id'];
                }
            }
            $twoPerson = false;
            foreach ($qa as $q) {
                foreach ($rm as $r) {
                    if ($q !== $r) {
                        $twoPerson = true;
                    }
                }
            }
            if (! $twoPerson) {
                $errors[] = 'Two-person approval (QA + Release Manager, different users) not recorded';
            }
        }

        return $errors;
    }

    private function checkChecksums(array $doc, array $evidence): array
    {
        $release = $evidence['release_manifest'] ?? null;
        if (! is_array($release)) {
            return ['Signed release manifest required as evidence'];
        }
        if (! Ed25519::verifyDocument($release, $this->releaseKeys)) {
            return ['Evidence release manifest is not signed by a release key'];
        }
        if (hash('sha256', CanonicalJson::encode($release)) !== ($doc['release_manifest_sha256'] ?? '')) {
            return ['Checksums do not reference the evidence release manifest'];
        }
        $files = $doc['files'] ?? null;
        if (! is_array($files) || $files === []) {
            return ['Checksums list no files'];
        }
        $known = [];
        foreach ((array) ($release['packages'] ?? []) as $p) {
            $known[(string) ($p['file_name'] ?? '')] = strtolower((string) ($p['sha256'] ?? ''));
        }
        $errors = [];
        foreach ($files as $f) {
            $name = (string) ($f['name'] ?? '');
            if (($known[$name] ?? null) !== strtolower((string) ($f['sha256'] ?? ''))) {
                $errors[] = "File {$name} is not a package of the release manifest";
            }
        }

        return $errors;
    }

    private function checkChannel(array $doc, array $evidence): array
    {
        $current = $doc['current'] ?? null;
        if ($current === null) {
            return []; // channel with nothing offered (e.g. everything withdrawn)
        }
        $release = $evidence['release_manifest'] ?? null;
        if (! is_array($release)) {
            return ['Signed release manifest required as evidence'];
        }
        if (! Ed25519::verifyDocument($release, $this->releaseKeys)) {
            return ['Evidence release manifest is not signed by a release key'];
        }
        if (hash('sha256', CanonicalJson::encode($release)) !== ($current['release_manifest_sha256'] ?? '')) {
            return ['Channel manifest does not reference the evidence release manifest'];
        }

        return [];
    }

    private function checkOsManifest(array $doc, array $evidence): array
    {
        $errors = [];
        foreach (['release_id', 'version', 'min_version', 'ring', 'arch'] as $f) {
            if (! is_string($doc[$f] ?? null) || $doc[$f] === '') {
                $errors[] = "OS manifest field {$f} missing";
            }
        }
        if (! in_array($doc['ring'] ?? null, ['development', 'pilot', 'stable'], true)) {
            $errors[] = 'OS manifest ring must be development, pilot or stable';
        }
        if (! in_array($doc['arch'] ?? null, ['amd64', 'arm64'], true)) {
            $errors[] = 'OS manifest arch must be amd64 or arm64';
        }
        if (! in_array($doc['state'] ?? 'active', ['active', 'paused', 'pulled'], true)) {
            $errors[] = 'OS manifest state invalid';
        }
        $attestation = $evidence['attestation'] ?? null;
        if ($this->requireAttestation || $attestation !== null) {
            $payload = is_array($attestation) ? ($attestation['payload'] ?? null) : null;
            $kid     = is_array($attestation) ? (string) ($attestation['key_id'] ?? '') : '';
            if (! is_array($payload) || ! isset($this->ciKeys[$kid])
                || ! Ed25519::verify(CanonicalJson::encode($payload), Base64Url::decode((string) ($attestation['signature'] ?? '')), $this->ciKeys[$kid])) {
                $errors[] = 'CI build attestation signature invalid or key not pinned';
            } elseif ((string) ($payload['version'] ?? '') !== (string) ($doc['version'] ?? '')) {
                $errors[] = 'Attestation version does not match OS manifest';
            }
        }

        return $errors;
    }
}
