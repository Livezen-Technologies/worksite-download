<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Services\EntitlementCatalogService;

/** The entitlement catalog: keys, each edition's values, and licence add-ons. */
class EntitlementsController extends BaseApiController
{
    public function index()
    {
        return (new EntitlementCatalogService())->matrix();
    }

    public function define()
    {
        return $this->ok((new EntitlementCatalogService())->define($this->body()), 201);
    }

    public function edition(string $code)
    {
        return (new EntitlementCatalogService())->setEditionValues($code, (array) ($this->body()['values'] ?? []));
    }

    public function licenseShow(string $id)
    {
        $lic = $this->licence($id);
        $svc = new EntitlementCatalogService();

        return ['addons' => $svc->licenseAddons((int) $lic['id']), 'resolved' => $svc->resolve($lic), 'edition' => $lic['edition']];
    }

    public function licenseUpdate(string $id)
    {
        $lic = $this->licence($id);
        $b   = $this->body();
        $svc = new EntitlementCatalogService();
        $svc->setLicenseAddons($lic, (array) ($b['values'] ?? []), (array) ($b['remove'] ?? []), $this->auth->user['email'] ?? null);

        return ['addons' => $svc->licenseAddons((int) $lic['id']), 'resolved' => $svc->resolve($lic), 'edition' => $lic['edition']];
    }

    private function licence(string $id): array
    {
        return db_connect()->table('licenses')->where('public_id', $id)->get()->getRowArray() ?? throw new ApiException('NOT_FOUND', 'Licence not found', 404);
    }
}
