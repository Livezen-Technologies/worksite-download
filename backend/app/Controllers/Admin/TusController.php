<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Services\Catalog;
use App\Services\PackageService;

/**
 * tus 1.0 server (core + creation + termination) for resumable package uploads
 * (FR-UC-UPL-01/02, NFR-C05). The upload must first be registered through
 * POST /admin/releases/{id}/packages; creation links to it via Upload-Metadata.
 */
class TusController extends BaseApiController
{
    private function tus($res)
    {
        // tus responses have no body; a non-HTML type also keeps dev tooling from appending one.
        return $res->setHeader('Tus-Resumable', '1.0.0')->setHeader('Cache-Control', 'no-store')->setContentType('application/offset+octet-stream')->setBody('');
    }

    public function options(?string $id = null)
    {
        return $this->tus($this->response->setStatusCode(204))
            ->setHeader('Tus-Version', '1.0.0')->setHeader('Tus-Extension', 'creation,termination')
            ->setHeader('Tus-Max-Size', (string) config('UpdateCenter')->uploadMaxBytes);
    }

    private function load(string $id): array
    {
        $upload = (new PackageService())->upload($id);
        $mine   = ($upload['user_id'] && (int) $upload['user_id'] === $this->auth->userId()) || ($upload['api_client_id'] && (int) $upload['api_client_id'] === $this->auth->clientId());
        if (! $mine) {
            throw ApiException::forbidden('Upload belongs to another user');
        }

        return $upload;
    }

    public function create()
    {
        if ($this->request->getHeaderLine('Tus-Resumable') !== '1.0.0') {
            throw new ApiException('TUS_VERSION', 'Tus-Resumable 1.0.0 required', 412);
        }
        $meta = [];
        foreach (explode(',', $this->request->getHeaderLine('Upload-Metadata')) as $pair) {
            $parts = explode(' ', trim($pair), 2);
            if ($parts[0] !== '') {
                $meta[$parts[0]] = isset($parts[1]) ? (string) base64_decode($parts[1], true) : '';
            }
        }
        $upload = $this->load((string) ($meta['upload_id'] ?? ''));
        $length = (int) $this->request->getHeaderLine('Upload-Length');
        if ($length !== (int) $upload['size_bytes']) {
            throw new ApiException('UPLOAD_SIZE_MISMATCH', 'Upload-Length differs from the registered size', 409);
        }

        return $this->tus($this->response->setStatusCode(201))
            ->setHeader('Location', '/api/v1/uploads/tus/' . $upload['public_id'])
            ->setHeader('Upload-Offset', (string) $upload['offset_bytes']);
    }

    public function head(string $id)
    {
        $upload = $this->load($id);
        if ($upload['status'] === 'ABORTED' || $upload['status'] === 'EXPIRED') {
            return $this->tus($this->response->setStatusCode(410));
        }

        return $this->tus($this->response->setStatusCode(200))
            ->setHeader('Upload-Offset', (string) $upload['offset_bytes'])
            ->setHeader('Upload-Length', (string) $upload['size_bytes']);
    }

    public function patch(string $id)
    {
        if ($this->request->getHeaderLine('Content-Type') !== 'application/offset+octet-stream') {
            throw new ApiException('TUS_CONTENT_TYPE', 'Content-Type must be application/offset+octet-stream', 415);
        }
        $upload = $this->load($id);
        if (strtotime($upload['expires_at'] . ' UTC') < time()) {
            db_connect()->table('uploads')->where('id', $upload['id'])->update(['status' => 'EXPIRED']);

            return $this->tus($this->response->setStatusCode(410));
        }
        $offset = (int) $this->request->getHeaderLine('Upload-Offset');
        $body   = $this->request->getBody();
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, (string) $body);
        rewind($stream);
        if ($body === '' || $body === null) {
            fclose($stream);
            $stream = fopen('php://input', 'rb');
        }
        $new = (new PackageService())->appendChunk($upload, $offset, $stream);
        fclose($stream);

        return $this->tus($this->response->setStatusCode(204))->setHeader('Upload-Offset', (string) $new);
    }

    public function terminate(string $id)
    {
        $upload = $this->load($id);
        $svc    = new PackageService();
        @unlink($svc->tempPath($upload));
        db_connect()->table('uploads')->where('id', $upload['id'])->update(['status' => 'ABORTED', 'updated_at' => Catalog::now()]);

        return $this->tus($this->response->setStatusCode(204));
    }
}
