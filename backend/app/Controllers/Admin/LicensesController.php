<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Services\LicenseService;

/**
 * WorkSuite Server licences: generate, renew, suspend, resume, revoke, deactivate a server.
 */
class LicensesController extends BaseApiController
{
    public function index()
    {
        [$limit, $offset] = $this->paging(50);

        return (new LicenseService())->list($this->request->getGet(), $limit, $offset);
    }

    public function show(string $id)
    {
        return (new LicenseService())->detail($id);
    }

    /** The generated key is returned once, here, and never again. */
    public function create()
    {
        [$licence, $key] = (new LicenseService())->create($this->body(), $this->auth->user['email'] ?? null);

        return $this->ok($licence + ['license_key' => $key], 201);
    }

    public function update(string $id)
    {
        return (new LicenseService())->update($id, $this->body());
    }

    public function renew(string $id)
    {
        return (new LicenseService())->renew($id, $this->body());
    }

    public function status(string $id, string $action)
    {
        $status = ['suspend' => 'SUSPENDED', 'resume' => 'ACTIVE', 'revoke' => 'REVOKED'][$action];
        if ($status === 'REVOKED') {
            $this->requireStepUp();
        }

        return (new LicenseService())->setStatus($id, $status, $this->body()['reason'] ?? null);
    }

    public function deactivate(string $id, string $activationId)
    {
        return (new LicenseService())->deactivate($id, (int) $activationId, $this->body()['reason'] ?? null);
    }
}
