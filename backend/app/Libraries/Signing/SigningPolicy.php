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
 *  CHANNEL manifest — `current.release_manifest_sha256` must be the hash of a
 *  release manifest that verifies under a release key.
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
            'ROTATION' => [],
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
}
