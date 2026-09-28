<?php

namespace App\Controllers\BugTrace;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Libraries\BugTrace\Bt;
use App\Services\BugTrace\SymbolService;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Debug symbol upload for CI (WF-C37): POST /api/v1/admin/bugtrace/symbols
 * (multipart: file[, files[]], release_id, project?, kind?, platform?,
 * artifact?, debug_id?) or a raw body with the same fields as query
 * parameters plus ?file_name= — permission bugtrace.symbols.upload. Listing returns
 * metadata only; symbol content is never served (AC-C75).
 */
class SymbolsController extends BaseApiController
{
    public function upload(): ResponseInterface
    {
        if (! str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'multipart/form-data')) {
            return $this->ok($this->uploadRaw(), 201);
        }
        $fields = $this->request->getPost() ?? [];
        $files  = [];
        foreach (['file', 'sourcemap', 'symbol'] as $name) {
            $f = $this->request->getFile($name);
            if (Bt::uploadOk($f)) {
                $files[] = $f;
            }
        }
        foreach ((array) ($this->request->getFileMultiple('files') ?? []) as $f) {
            if (Bt::uploadOk($f)) {
                $files[] = $f;
            }
        }
        if ($files === []) {
            throw ApiException::validation(['file' => 'At least one symbol file is required']);
        }
        $out = [];
        foreach ($files as $f) {
            $out[] = (new SymbolService())->upload($f->getTempName(), $f->getClientName(), $fields, $this->auth);
        }

        return $this->ok(count($out) === 1 ? $out[0] : ['data' => $out], 201);
    }

    /** curl --data-binary @mail-8c1.js.map ".../symbols?release_id=rel_…&file_name=mail-8c1.js.map" */
    private function uploadRaw(): array
    {
        $raw = (string) $this->request->getBody();
        if ($raw === '') {
            throw ApiException::validation(['file' => 'Symbol file body is required']);
        }
        if (strtolower($this->request->getHeaderLine('Content-Encoding')) === 'gzip') {
            $raw = @gzdecode($raw, 512 * 1024 * 1024);
            if ($raw === false) {
                throw new ApiException('INVALID_ENCODING', 'Body is not valid gzip', 400);
            }
        }
        $fields = $this->request->getGet() ?? [];
        $name   = basename((string) ($fields['file_name'] ?? 'symbols.map'));
        $tmp    = tempnam(sys_get_temp_dir(), 'bts');
        file_put_contents($tmp, $raw);
        try {
            return (new SymbolService())->upload($tmp, $name, $fields, $this->auth);
        } finally {
            @unlink($tmp);
        }
    }

    public function index(): array
    {
        return (new SymbolService())->list($this->request->getGet() ?? []);
    }
}
