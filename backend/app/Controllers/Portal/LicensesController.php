<?php

namespace App\Controllers\Portal;

use App\Controllers\BaseApiController;
use App\Services\PortalLicenseService;

/** Portal › Licences: a customer's own WorkSuite Server licences (IMPL-LIC-01 P3). */
class LicensesController extends BaseApiController
{
    private function svc(): PortalLicenseService
    {
        return new PortalLicenseService($this->auth->user ?? []);
    }

    public function index()
    {
        return $this->svc()->list();
    }

    public function show(string $id)
    {
        return $this->svc()->detail($id);
    }

    public function reveal(string $id)
    {
        $this->requireStepUp();

        return $this->svc()->reveal($id);
    }

    public function replaceKey(string $id)
    {
        $this->requireStepUp();

        return $this->svc()->replaceKey($id);
    }

    public function deactivate(string $id, string $activationId)
    {
        $this->requireStepUp();

        return $this->svc()->deactivate($id, (int) $activationId, $this->body()['reason'] ?? null);
    }

    public function offline(string $id)
    {
        return $this->svc()->offline($id, $this->body()['request'] ?? null);
    }

    public function editionPreview(string $id)
    {
        return $this->svc()->editionPreview($id, (string) ($this->request->getGet('edition') ?? ''));
    }

    public function trial()
    {
        $b = $this->body();

        return $this->ok($this->svc()->startTrial((string) ($b['edition'] ?? ''), $b['organization'] ?? null), 201);
    }
}
