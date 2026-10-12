<?php

namespace App\Controllers\Server;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Services\ServerAutoUpdateService;
use App\Services\ServerUpdateService;
use App\Services\UpdateResolverService;

/**
 * Update API for WorkSuite Server installations (§17.10, FR-UC-SRV-10..14) and the
 * Core System Update Service endpoints (WS-CORE-SYSUPDATE-SRS-001 §10): register,
 * auto-update plan and policy sync.
 */
class ServerController extends BaseApiController
{
    private function installation(): array
    {
        return (new ServerUpdateService())->installationFromLicense($this->request->getHeaderLine('Authorization') ?: null);
    }

    public function update()
    {
        $inst = $this->installation();

        return (new ServerUpdateService())->check($inst, $this->request->getGet());
    }

    public function grant()
    {
        $this->installation();

        return (new UpdateResolverService())->redeemGrant((string) ($this->body()['grant'] ?? ''));
    }

    public function report()
    {
        return (new ServerUpdateService())->report($this->installation(), $this->body());
    }

    public function events()
    {
        $b = $this->body();

        return $this->ok((new ServerUpdateService())->events($this->installation(), (array) ($b['events'] ?? [$b])), 202);
    }

    public function ack()
    {
        (new ServerUpdateService())->acknowledgeAdvisory($this->installation());

        return ['ok' => true];
    }

    /** The new §10 endpoints accept only the installation key (`Authorization: Installation <key>`). */
    private function keyInstallation(): array
    {
        $h = $this->request->getHeaderLine('Authorization');
        if (! str_starts_with($h, 'Installation ')) {
            throw new ApiException('INSTALLATION_KEY_REQUIRED', 'Installation key required (Authorization: Installation <key>) — register the installation first', 401);
        }

        return (new ServerAutoUpdateService())->installationFromKey($h);
    }

    /** POST /server/installations/register — licence token in, installation_id + installation key out. */
    public function register()
    {
        [$doc, $created] = (new ServerAutoUpdateService())->register($this->request->getHeaderLine('Authorization') ?: null, $this->body());

        return $this->ok($doc, $created ? 201 : 200);
    }

    /** GET /server/auto-update/plan — signed plan, 2-hour expiry. */
    public function plan()
    {
        return (new ServerAutoUpdateService())->plan($this->keyInstallation());
    }

    /** GET /server/auto-update/policy — current mirror and its version (ETag). */
    public function getPolicy()
    {
        $doc = (new ServerAutoUpdateService())->getPolicy($this->keyInstallation());

        return $this->ok($doc)->setHeader('ETag', '"' . $doc['version'] . '"');
    }

    /** PUT /server/auto-update/policy — If-Match required; 412 with the newer version when stale. */
    public function putPolicy()
    {
        $inst = $this->keyInstallation();
        $svc  = new ServerAutoUpdateService();
        $doc  = $svc->putPolicy($inst, ServerAutoUpdateService::parseIfMatch($this->request->getHeaderLine('If-Match')), $this->body());

        return $this->ok($doc)->setHeader('ETag', '"' . $doc['version'] . '"');
    }
}
