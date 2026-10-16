<?php

namespace App\Controllers\Os;

use App\Controllers\BaseApiController;
use App\Exceptions\ApiException;
use App\Services\OsUpdateService;

/**
 * WorkSuite OS update endpoints (§18): signed ring manifests, the APT repository, ISO
 * downloads, and the CI / release-manager publishing API.
 */
class OsController extends BaseApiController
{
    private function svc(): OsUpdateService
    {
        return new OsUpdateService();
    }

    // ---- public, read-only (integrity comes from Ed25519 / the APT InRelease signature) ----

    /** GET /os/manifests/{ring}/{arch}.json */
    public function manifest(string $ring, string $file)
    {
        if (! preg_match('/^([a-z0-9]+)\.json$/', $file, $m)) {
            throw ApiException::notFound();
        }

        return $this->manifestResponse($ring, $m[1]);
    }

    /** GET desktop/v1/releases/manifest?platform=os&ring=&arch= (the WorkSuite server's path for the same document). */
    public function desktopManifest()
    {
        if ($this->request->getGet('platform') !== 'os') {
            throw ApiException::notFound('NOT_FOUND', 'only platform=os is served here');
        }

        return $this->manifestResponse((string) $this->request->getGet('ring'), (string) $this->request->getGet('arch'));
    }

    private function manifestResponse(string $ring, string $arch)
    {
        $json = $this->svc()->manifest($ring, $arch) ?? throw ApiException::notFound('NO_RELEASE', 'No WorkSuite OS release on this ring');

        return $this->response->setStatusCode(200)->setContentType('application/json')
            ->setHeader('Cache-Control', 'public, max-age=300')->setBody($json);
    }

    /** GET api/v1/os/releases — download page data. */
    public function releases()
    {
        return $this->svc()->publicReleases();
    }

    /** GET api/v1/os/keys — release public keys (PEM) for /usr/share/worksuite/keys/release. */
    public function keys()
    {
        return $this->svc()->publicKeys();
    }

    /** GET /os/apt/{path} — dists/ is served directly, pool/ redirects to a signed CDN URL. */
    public function apt(string ...$segments)
    {
        $path = implode('/', $segments);
        $key  = $this->svc()->aptKey($path) ?? throw ApiException::notFound('NOT_FOUND', 'Not in the WorkSuite OS repository');
        $st   = $this->svc()->storage();
        if (str_starts_with($path, 'pool/')) {
            return $this->response->redirect($st->signedDownloadUrl($key, config('UpdateCenter')->downloadUrlTtl, basename($key)), 'auto', 302);
        }
        $h    = $st->stream($key);
        $body = is_resource($h) ? (string) stream_get_contents($h) : (string) $h;

        return $this->response->setStatusCode(200)->setHeader('Cache-Control', 'public, max-age=60')
            ->setContentType(str_ends_with($path, 'InRelease') || str_ends_with($path, '/Release') || str_ends_with($path, '/Packages') ? 'text/plain' : 'application/octet-stream')
            ->setBody($body);
    }

    /** GET /os/download/{version}/{arch}/{file} — ISO, .sha256 or SBOM via a short-lived signed URL. */
    public function download(string $version, string $arch, string $file)
    {
        $key = $this->svc()->artifactKey($version, $arch, $file) ?? throw ApiException::notFound('NOT_FOUND', 'File not found');

        return $this->response->redirect($this->svc()->storage()->signedDownloadUrl($key, config('UpdateCenter')->downloadUrlTtl, $file), 'auto', 302);
    }

    // ---- CI (API client with os.publish) ----

    /** PUT api/v1/os/artifacts/{version}/{arch}/{file} — raw body. */
    public function putArtifact(string $version, string $arch, string $file)
    {
        $tmp = $this->bodyToTemp();
        try {
            return $this->ok($this->svc()->putArtifact($version, $arch, $file, $tmp), 201);
        } finally {
            @unlink($tmp);
        }
    }

    /** PUT api/v1/os/apt — tar.gz of the publish.sh output tree. */
    public function putApt()
    {
        $tmp = $this->bodyToTemp('.tar.gz');
        try {
            return $this->svc()->importApt($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** POST api/v1/os/releases — publish to a ring (CI may publish to development and pilot only). */
    public function publish()
    {
        $b    = $this->body();
        $ring = OsUpdateService::ring((string) ($b['ring'] ?? 'development'));
        if ($ring === 'stable' && ! $this->auth->can('release.publish')) {
            throw ApiException::forbidden('Publishing to stable needs release.publish (promote from pilot instead)');
        }

        return $this->ok($this->svc()->publish($b, $this->actor()), 201);
    }

    // ---- release management (staff with release.publish) ----

    public function list()
    {
        return $this->svc()->list();
    }

    /** POST api/v1/admin/os/releases/{version}/{ring}/{action}  body {arch?, reason?, rollout_percent?} */
    public function action(string $version, string $ring, string $action)
    {
        $b = $this->body();
        if ($action === 'promote') {
            return $this->svc()->promote($version, $ring, (string) ($b['to'] ?? ''), $b['arch'] ?? null, $this->actor());
        }

        return $this->svc()->action($version, $ring, $b['arch'] ?? null, $action, $b, $this->actor());
    }

    /** POST api/v1/admin/os/emergency — security release to every ring at once. */
    public function emergency()
    {
        return $this->ok($this->svc()->emergency($this->body(), $this->actor()), 201);
    }

    private function actor(): string
    {
        return (string) ($this->auth->actorLabel() ?? $this->auth->actorType());
    }

    private function bodyToTemp(string $suffix = ''): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'os-up-') . $suffix;
        $in  = fopen('php://input', 'rb');
        $out = fopen($tmp, 'wb');
        $n   = $in ? stream_copy_to_stream($in, $out) : 0;
        fclose($out);
        if ($in) {
            fclose($in);
        }
        if (! $n) { // php://input is empty under the test harness / some SAPIs
            file_put_contents($tmp, (string) $this->request->getBody());
        }
        if (filesize($tmp) === 0) {
            @unlink($tmp);
            throw ApiException::validation(['body' => 'Empty upload']);
        }

        return $tmp;
    }
}
