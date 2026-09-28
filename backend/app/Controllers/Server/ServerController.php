<?php

namespace App\Controllers\Server;

use App\Controllers\BaseApiController;
use App\Services\ServerUpdateService;
use App\Services\UpdateResolverService;

/**
 * Update API for WorkSuite Server installations (§17.10, FR-UC-SRV-10..14).
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
}
