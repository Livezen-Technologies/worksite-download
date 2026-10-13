<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Services\LicenseRiskService;

/** The licence risk engine's flags (Server › Risk). */
class LicenseRiskController extends BaseApiController
{
    public function index()
    {
        [$limit, $offset] = $this->paging(100);

        return (new LicenseRiskService())->list($this->request->getGet(), $limit, $offset);
    }

    public function status(string $id, string $action)
    {
        $status = ['acknowledge' => 'ACKNOWLEDGED', 'resolve' => 'RESOLVED', 'reopen' => 'OPEN'][$action];

        return (new LicenseRiskService())->setStatus((int) $id, $status, $this->body()['resolution'] ?? null, $this->auth->user['email'] ?? null);
    }
}
