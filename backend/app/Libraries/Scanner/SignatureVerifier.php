<?php

namespace App\Libraries\Scanner;

/**
 * Verifies platform code signatures with OS-independent tools, reading the
 * file only (§12.1): osslsigncode (Authenticode), rcodesign (Developer ID +
 * notarization ticket), gpg / rpm (Linux). Mode "skip" is for dev only.
 */
final class SignatureVerifier
{
    public function __construct(
        private readonly string $mode,
        private readonly string $expectedPublisher,
        private readonly string $gpgKeyring = '',
    ) {
    }

    /**
     * @return array{status: 'VALID'|'INVALID'|'SKIPPED'|'NOT_REQUIRED', kind: string, detail: string, signer: ?string}
     */
    public function verify(string $localPath, string $platform, string $type, ?string $detachedSigPath = null): array
    {
        $kind = match ($platform) {
            'windows' => 'authenticode',
            'macos'   => 'developer_id',
            'linux'   => 'gpg',
            default   => 'none',
        };
        if ($kind === 'none' || in_array($type, ['zip'], true) && $platform === 'server') {
            return ['status' => 'NOT_REQUIRED', 'kind' => 'release-key', 'detail' => 'Covered by the release-key manifest signature', 'signer' => null];
        }
        if ($this->mode === 'skip') {
            return ['status' => 'SKIPPED', 'kind' => $kind, 'detail' => 'Signature verification disabled (development)', 'signer' => null];
        }

        return match ($kind) {
            'authenticode' => $this->run(['osslsigncode', 'verify', '-in', $localPath], $kind, '/Signer.*?CN=([^,\n\/]+)/s', 'Signature verification: ok'),
            'developer_id' => $this->run(['rcodesign', 'verify', $localPath], $kind, '/(Developer ID Application: [^\n(]+)/', null),
            'gpg'          => $type === 'rpm'
                ? $this->run(['rpm', '-K', $localPath], $kind, null, 'digests signatures OK')
                : ($detachedSigPath !== null
                    ? $this->run(array_merge(['gpg', '--batch', '--verify'], $this->gpgKeyring !== '' ? ['--keyring', $this->gpgKeyring, '--no-default-keyring'] : [], [$detachedSigPath, $localPath]), $kind, '/Good signature from "([^"]+)"/', null)
                    : ['status' => 'INVALID', 'kind' => $kind, 'detail' => 'Detached .sig not provided for GPG verification', 'signer' => null]),
        };
    }

    /** @param list<string> $cmd */
    private function run(array $cmd, string $kind, ?string $signerPattern, ?string $okMarker): array
    {
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return ['status' => 'INVALID', 'kind' => $kind, 'detail' => $cmd[0] . ' not available on this worker', 'signer' => null];
        }
        $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($proc);
        $ok   = $code === 0 && ($okMarker === null || str_contains($out, $okMarker));
        $signer = null;
        if ($signerPattern !== null && preg_match($signerPattern, $out, $m)) {
            $signer = trim($m[1]);
        }
        if ($ok && $signer !== null && $this->expectedPublisher !== '' && ! str_contains($signer, $this->expectedPublisher)) {
            return ['status' => 'INVALID', 'kind' => $kind, 'detail' => "Wrong publisher: {$signer}", 'signer' => $signer];
        }

        return ['status' => $ok ? 'VALID' : 'INVALID', 'kind' => $kind, 'detail' => trim(substr($out, 0, 1000)), 'signer' => $signer];
    }
}
