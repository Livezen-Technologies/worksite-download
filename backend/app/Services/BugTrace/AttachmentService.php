<?php

namespace App\Services\BugTrace;

use App\Exceptions\ApiException;
use App\Libraries\BugTrace\Bt;
use App\Libraries\BugTrace\Scrubber;
use App\Libraries\BugTrace\Vault;
use App\Libraries\SecretStore;
use App\Libraries\Ulid;

/**
 * Report / event attachments (FR-BT-REP-03/04, FR-BT-PRV-07): screenshots,
 * scrubbed logs, recordings, files and minidumps. Bytes are encrypted at rest
 * under bugtrace/; uploads stay unlinked (and expire after 24 h) until a
 * report or event references their `att_…` id.
 */
class AttachmentService
{
    public const LIMITS = [
        'screenshot' => 10 * 1024 * 1024,
        'log'        => 5 * 1024 * 1024,
        'recording'  => 20 * 1024 * 1024,
        'file'       => 20 * 1024 * 1024,
        'minidump'   => 20 * 1024 * 1024,
    ];

    private const TYPES = [
        'screenshot' => ['image/png', 'image/jpeg', 'image/webp'],
        'log'        => ['text/plain', 'application/json', 'application/x-ndjson'],
        'recording'  => ['video/webm', 'video/mp4'],
        'file'       => null,
        'minidump'   => null,
    ];

    public function checkKind(string $kind, array $policy): void
    {
        if (! isset(self::LIMITS[$kind]) || $kind === 'minidump') {
            throw ApiException::validation(['kind' => 'One of screenshot, log, recording, file']);
        }
        if ($kind === 'screenshot' && ! $policy['screenshots']) {
            throw new ApiException('POLICY_DISABLED', 'Screenshots are turned off by your organisation', 403);
        }
        if ($kind === 'log' && ! $policy['logs']) {
            throw new ApiException('POLICY_DISABLED', 'Diagnostic logs are turned off by your organisation', 403);
        }
        if (! $policy['user_reports']) {
            throw new ApiException('POLICY_DISABLED', 'Bug reports are turned off by your organisation', 403);
        }
    }

    /**
     * Store bytes from a local file. Returns the attachment summary.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    public function storeFile(array $project, string $kind, string $path, string $contentType, array $meta = [], ?string $publicId = null): array
    {
        $size = filesize($path) ?: 0;
        if ($size > self::LIMITS[$kind]) {
            throw new ApiException('PAYLOAD_TOO_LARGE', "Attachment exceeds " . (self::LIMITS[$kind] >> 20) . ' MB', 413);
        }
        $contentType = strtolower(trim(explode(';', $contentType)[0]));
        $allowed     = self::TYPES[$kind];
        if ($allowed !== null && ! in_array($contentType, $allowed, true)) {
            throw new ApiException('UNSUPPORTED_MEDIA_TYPE', 'Content type not allowed for ' . $kind, 415);
        }
        if ($kind === 'screenshot' && ! self::isImage($path)) {
            throw new ApiException('UNSUPPORTED_MEDIA_TYPE', 'Screenshot is not a PNG, JPEG or WebP image', 415);
        }
        if ($kind === 'log') {
            // Logs are scrubbed with the same rules as events before storage (§18.8).
            $scrubber = new Scrubber(IngestService::customScrubRules((int) $project['id']));
            $lines    = preg_split('/\r\n|\n/', (string) file_get_contents($path));
            file_put_contents($path, implode("\n", array_map([$scrubber, 'scrubString'], $lines)));
            $meta['scrubbed'] = $scrubber->count();
        }
        $db       = Bt::db();
        $publicId ??= Ulid::prefixed('att');
        $key      = 'bugtrace/' . ($kind === 'minidump' ? 'minidumps' : 'attachments') . '/' . $project['id'] . '/' . gmdate('Y/m') . '/' . $publicId;
        $info     = Vault::putFile($key, $path);
        $row      = [
            'project_id'   => $project['id'],
            'kind'         => $kind,
            'storage_key'  => $key,
            'content_type' => $contentType ?: 'application/octet-stream',
            'size'         => $info['size'],
            'sha256'       => $info['sha256'],
            'meta'         => Bt::json($meta === [] ? new \stdClass() : $meta),
            'status'       => 'STORED',
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 86400),
            'updated_at'   => Bt::now(),
        ];
        if ($db->table('bt_attachments')->where('public_id', $publicId)->countAllResults() > 0) {
            $db->table('bt_attachments')->where('public_id', $publicId)->update($row);
        } else {
            $db->table('bt_attachments')->insert($row + ['public_id' => $publicId, 'created_at' => Bt::now()]);
        }

        return ['id' => $publicId, 'kind' => $kind, 'size' => $info['size'], 'sha256' => $info['sha256'], 'status' => 'STORED'];
    }

    /**
     * Two-step upload: reserve an id and return a short-lived PUT URL.
     *
     * @return array<string, mixed>
     */
    public function init(array $project, string $kind, int $size, string $contentType): array
    {
        if ($size <= 0 || $size > self::LIMITS[$kind]) {
            throw new ApiException('PAYLOAD_TOO_LARGE', "Attachment exceeds " . (self::LIMITS[$kind] >> 20) . ' MB', 413);
        }
        $id  = Ulid::prefixed('att');
        $exp = time() + 900;
        Bt::db()->table('bt_attachments')->insert([
            'public_id' => $id, 'project_id' => $project['id'], 'kind' => $kind, 'storage_key' => '', 'content_type' => $contentType,
            'size' => $size, 'status' => 'PENDING', 'meta' => '{}', 'expires_at' => gmdate('Y-m-d H:i:s', $exp), 'created_at' => Bt::now(), 'updated_at' => Bt::now(),
        ]);

        return [
            'id'         => $id,
            'status'     => 'PENDING',
            'upload_url' => '/api/v1/bugtrace/attachments/' . $id . '/upload?exp=' . $exp . '&sig=' . self::uploadSig($id, $exp),
            'method'     => 'PUT',
            'headers'    => ['Content-Type' => $contentType],
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $exp),
        ];
    }

    public static function uploadSig(string $id, int $exp): string
    {
        return hash_hmac('sha256', 'bt-upload|' . $id . '|' . $exp, SecretStore::appSecret());
    }

    /** @return array<string, mixed> */
    public function completeUpload(string $id, int $exp, string $sig, string $path, string $contentType): array
    {
        if ($exp < time() || ! hash_equals(self::uploadSig($id, $exp), $sig)) {
            throw new ApiException('UPLOAD_URL_INVALID', 'Upload URL invalid or expired', 403);
        }
        $row = Bt::db()->table('bt_attachments')->where('public_id', $id)->where('status', 'PENDING')->get()->getRowArray();
        if (! $row) {
            throw ApiException::notFound('NOT_FOUND', 'Upload not found');
        }
        $project = EventProcessor::project((int) $row['project_id']);

        return $this->storeFile($project, (string) $row['kind'], $path, $contentType ?: (string) $row['content_type'], [], $id);
    }

    private static function isImage(string $path): bool
    {
        $h = (string) file_get_contents($path, false, null, 0, 16);

        return str_starts_with($h, "\x89PNG\r\n\x1a\n") || str_starts_with($h, "\xFF\xD8\xFF") || (str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WEBP');
    }

    /** Bytes for an authorised staff download (decrypted). */
    public function read(string $publicId): array
    {
        $a = Bt::db()->table('bt_attachments')->where('public_id', $publicId)->where('status', 'STORED')->get()->getRowArray();
        if (! $a) {
            throw ApiException::notFound('NOT_FOUND', 'Attachment not found');
        }

        return [$a, Vault::getBytes((string) $a['storage_key'])];
    }
}
