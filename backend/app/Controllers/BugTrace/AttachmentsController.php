<?php

namespace App\Controllers\BugTrace;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Libraries\BugTrace\Vault;
use App\Services\BugTrace\AttachmentService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Staff-only attachment access (FR-BT-PRV-07): screenshots, logs,
 * recordings and minidumps are decrypted and streamed only for a signed-in
 * user with bugtrace.triage presenting a short-lived (5 min) HMAC-signed URL.
 */
class AttachmentsController extends BaseApiController
{
    /** GET /attachments/{id}/url → a fresh signed URL */
    public function url(string $id): array
    {
        [$a] = (new AttachmentService())->read($id);

        return ['id' => $a['public_id'], 'url' => Vault::signedPath($a['public_id']), 'expires_in' => 300];
    }

    /** GET /attachments/{id}/download?exp=&sig= */
    public function download(string $id): ResponseInterface
    {
        if (! Vault::verify($id, (int) $this->request->getGet('exp'), (string) $this->request->getGet('sig'))) {
            throw new ApiException('URL_EXPIRED', 'Signed URL invalid or expired', 403);
        }
        [$a, $bytes] = (new AttachmentService())->read($id);
        service('audit')->record('bugtrace.attachment.viewed', 'bt_attachment', $id, null, ['kind' => $a['kind'], 'owner' => $a['owner_id']]);
        $inline = in_array($a['content_type'], ['image/png', 'image/jpeg', 'image/webp', 'video/webm', 'video/mp4'], true);

        return $this->response->setStatusCode(200)
            ->setContentType($inline ? $a['content_type'] : 'application/octet-stream')
            ->setHeader('Content-Disposition', ($inline ? 'inline' : 'attachment') . '; filename="' . $id . self::ext((string) $a['content_type'], (string) $a['kind']) . '"')
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->setBody($bytes);
    }

    private static function ext(string $ct, string $kind): string
    {
        return match ($ct) {
            'image/png'  => '.png',
            'image/jpeg' => '.jpg',
            'image/webp' => '.webp',
            'video/webm' => '.webm',
            'video/mp4'  => '.mp4',
            'text/plain' => '.log',
            default      => $kind === 'minidump' ? '.dmp' : '.bin',
        };
    }
}
