<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Services\Catalog;
use App\Services\ServerUpdateService;

/**
 * Installations registry and managed fleet (FR-UC-SRV-10/15/16).
 */
class InstallationsController extends BaseApiController
{
    public function index()
    {
        [$limit, $offset] = $this->paging(100);

        return (new ServerUpdateService())->installations($this->request->getGet(), $limit, $offset);
    }

    public function stats()
    {
        return (new ServerUpdateService())->stats();
    }

    public function fleetIndex()
    {
        $svc = new ServerUpdateService();
        $app = $svc->serverApp();
        $out = [];
        foreach (db_connect()->table('releases')->where('application_id', $app['id'])->whereIn('status', ['PUBLISHED', 'PAUSED'])->orderBy('version_sort', 'DESC')->get()->getResultArray() as $r) {
            $out[] = ReleasesController::present($r) + ['campaign' => $svc->campaign($r)];
        }
        $managed = db_connect()->table('server_installations')->where('mode', 'MANAGED')->countAllResults();

        return ['data' => $out, 'managed_installations' => $managed];
    }

    public function fleet(string $releaseId, string $action)
    {
        $this->requireStepUp();

        return (new ServerUpdateService())->fleet(Catalog::release($releaseId), $action);
    }
}
