<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Services\Catalog;
use App\Services\ServerAutoUpdateService;
use App\Services\ServerReleaseConsoleService;
use App\Services\ServerRingService;

/**
 * Server release console (WS-CORE-SYSUPDATE-SRS-001 §4, screens DL-1..DL-4):
 * list + KPIs, create with every server field, publish (QA ≠ Release Manager, step-up
 * MFA, type-to-confirm), ring rollout with the health gate, and fleet overrides.
 */
class ServerReleasesController extends BaseApiController
{
    private function console(): ServerReleaseConsoleService
    {
        return new ServerReleaseConsoleService($this->auth);
    }

    private function serverRelease(string $id): array
    {
        $r = Catalog::release($id);
        if (! ServerRingService::isServer($r)) {
            throw ApiException::notFound('RELEASE_NOT_FOUND', 'Not a WorkSuite Server release');
        }

        return $r;
    }

    /** DL-1: GET /admin/server/releases?channel=&status= */
    public function index()
    {
        return $this->console()->list($this->request->getGet());
    }

    /** DL-2: POST /admin/server/releases (FR-DL-REL-01). */
    public function create()
    {
        $r = $this->console()->create($this->body());

        return $this->ok(ReleasesController::present($r) + ['severity' => ServerRingService::severity($r)], 201);
    }

    /** PATCH /admin/server/releases/{id} — severity, commit, upgrade notes, reversibility, editions. */
    public function update(string $id)
    {
        $r = $this->console()->updateMeta($this->serverRelease($id), $this->body());

        return ReleasesController::present($r) + ['severity' => ServerRingService::severity($r)];
    }

    /** POST /admin/server/releases/{id}/publish — Release Manager, step-up MFA, type-to-confirm (FR-DL-REL-04). */
    public function publish(string $id)
    {
        $b = $this->body();
        $r = $this->serverRelease($id);
        if ((string) ($b['confirm_version'] ?? '') !== $r['version']) {
            throw ApiException::validation(['confirm_version' => 'Type the version number exactly to confirm']);
        }
        $this->requireStepUp();

        return ReleasesController::present($this->console()->publish($r, (string) $b['confirm_version'], $this->reason($b, false)));
    }

    /** DL-3: GET /admin/server/releases/{id}/rollout */
    public function rollout(string $id)
    {
        return (new ServerRingService())->present($this->serverRelease($id));
    }

    /** POST /admin/server/releases/{id}/rollout/{pause|resume|extend} (FR-DL-RING-02). */
    public function rolloutAction(string $id, string $action)
    {
        $b = $this->body();
        if ($action === 'pause' || $action === 'extend') {
            $this->reason($b);
        }
        $this->requireStepUp();

        return (new ServerRingService())->action($this->serverRelease($id), $action, $b);
    }

    /** PUT /admin/server/installations/{installation_id}/override — ring pin and policy locks (audited). */
    public function override(string $installationId)
    {
        $b = $this->body();
        $this->reason($b);
        $this->requireStepUp();
        $inst = db_connect()->table('server_installations')->where('installation_id', $installationId)->get()->getRowArray()
            ?? throw ApiException::notFound('INSTALLATION_NOT_FOUND', 'Installation not found');

        return (new ServerAutoUpdateService())->override($inst, $b, (string) $this->auth->actorLabel());
    }
}
