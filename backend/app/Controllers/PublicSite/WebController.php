<?php

namespace App\Controllers\PublicSite;

use App\Exceptions\ApiException;
use App\Libraries\Markdown\SafeMarkdown;
use App\Libraries\Storage\CdnUrlSigner;
use App\Libraries\Storage\LocalReleaseProvider;
use App\Libraries\Storage\StorageFactory;
use App\Libraries\SecretStore;
use App\Services\Catalog;
use App\Services\DownloadResolverService;
use App\Services\PublicCatalogService;
use CodeIgniter\Controller;

/**
 * Non-API web routes: the download resolver (302 → CDN), checksums.txt,
 * RSS/Atom feeds, sitemap/robots, API docs and dev-only CDN emulation.
 */
class WebController extends Controller
{
    public function download(string ...$segments)
    {
        $auth = service('authContext');
        $path = implode('/', $segments);
        $svc  = new DownloadResolverService($auth);
        try {
            $r = $svc->resolve(explode('/', $path), [
                'user_agent' => (string) $this->request->getUserAgent(),
                'referrer'   => $this->request->getHeaderLine('Referer'),
                'country'    => strtoupper($this->request->getHeaderLine('CF-IPCountry')),
                'license'    => $this->request->getHeaderLine('Authorization') ?: ($this->request->getGet('license') ?: null),
                'source'     => str_contains((string) $this->request->getUserAgent(), 'WorkSuite') ? 'desktop' : 'web',
            ]);
        } catch (ApiException $e) {
            return $this->errorPage($e, $segments[0] ?? null);
        }

        return $this->response->redirect($r['url'], 'auto', 302)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-WorkSuite-Release', $r['release']['version'])
            ->setHeader('X-WorkSuite-SHA256', (string) $r['package']['sha256']);
    }

    private function errorPage(ApiException $e, ?string $slug)
    {
        $app = null;
        try {
            $app = $slug ? Catalog::app($slug) : null;
        } catch (\Throwable) {
        }
        $alternatives = [];
        if ($app && in_array($e->errorCode, ['DISTRIBUTION_DEGRADED', 'RELEASE_NOT_AVAILABLE'], true)) {
            try {
                $latest = (new PublicCatalogService(service('authContext')))->app($app['slug'])['latest'];
                foreach (array_slice($latest['packages'] ?? [], 0, 8) as $p) {
                    $alternatives[] = [$p['label'], $p['download_url']];
                }
            } catch (\Throwable) {
            }
        }
        if ($e->errorCode === 'VERSION_UNSUPPORTED' && ! empty($e->details['latest_url'])) {
            $alternatives[] = ['Install ' . $e->details['latest'] . ' (latest)', $e->details['latest_url']];
        }
        $titles = ['DISTRIBUTION_DEGRADED' => 'Download temporarily unavailable', 'VERSION_UNSUPPORTED' => 'This version can no longer be downloaded', 'CHANNEL_FORBIDDEN' => 'Sign-in required', 'LICENSE_TOKEN_INVALID' => 'License required', 'RATE_LIMITED' => 'Too many downloads'];
        $html   = view('web/download_error', [
            'status' => $e->status, 'title' => $titles[$e->errorCode] ?? 'Download not found', 'message' => $e->getMessage(),
            'appName' => $app ? $app['name'] : null, 'appUrl' => $app ? '/apps/' . $app['slug'] : null,
            'alternatives' => $alternatives, 'retryAfter' => $e->headers()['Retry-After'] ?? null,
        ]);
        foreach ($e->headers() as $k => $v) {
            $this->response->setHeader($k, $v);
        }

        return $this->response->setStatusCode($e->status)->setHeader('Cache-Control', 'no-store')->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'")->setBody($html);
    }

    public function checksums(string $slug, string $version)
    {
        try {
            $txt = (new PublicCatalogService(service('authContext')))->checksums($slug, $version);
        } catch (ApiException $e) {
            return $this->response->setStatusCode($e->status)->setContentType('text/plain')->setBody($e->getMessage() . "\n");
        }

        return $this->response->setContentType('text/plain; charset=utf-8')->setHeader('Cache-Control', 'public, max-age=300')
            ->setHeader('Content-Disposition', 'inline; filename="checksums.txt"')->setBody($txt);
    }

    private function feedItems(?string $slug): array
    {
        return (new PublicCatalogService(null))->changelog($slug, 50, 0)['data'];
    }

    public function rss(?string $slug = null)
    {
        $base  = rtrim(config('UpdateCenter')->publicBaseUrl, '/');
        $items = $this->feedItems($slug);
        $title = $slug ? Catalog::app($slug)['name'] . ' releases' : 'WorkSuite releases';
        $x     = static fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml   = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\"><channel>"
            . '<title>' . $x($title) . '</title><link>' . $x($base . ($slug ? "/apps/{$slug}/changelog" : '/changelog')) . '</link>'
            . '<description>' . $x($title . ' — release notes from WorkSuite Downloads') . '</description><language>en</language>'
            . '<atom:link href="' . $x($base . ($slug ? "/apps/{$slug}" : '') . '/changelog/feed') . '" rel="self" type="application/rss+xml"/>';
        foreach ($items as $i) {
            $url = $base . $i['notes_url'];
            $xml .= '<item><title>' . $x($i['app']['name'] . ' ' . $i['version']) . '</title><link>' . $x($url) . '</link><guid isPermaLink="true">' . $x($url) . '</guid>'
                . '<pubDate>' . gmdate(DATE_RSS, strtotime((string) ($i['published_at'] ?? 'now'))) . '</pubDate>'
                . '<description>' . $x(($i['summary'] ? '<p>' . htmlspecialchars($i['summary']) . '</p>' : '') . $i['changelog']['html']) . '</description></item>';
        }
        $xml .= '</channel></rss>';

        return $this->response->setContentType('application/rss+xml; charset=utf-8')->setHeader('Cache-Control', 'public, max-age=300')->setBody($xml);
    }

    public function atom(?string $slug = null)
    {
        $base  = rtrim(config('UpdateCenter')->publicBaseUrl, '/');
        $items = $this->feedItems($slug);
        $title = $slug ? Catalog::app($slug)['name'] . ' releases' : 'WorkSuite releases';
        $x     = static fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $self  = $base . ($slug ? "/apps/{$slug}" : '') . '/changelog/atom';
        $xml   = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<feed xmlns=\"http://www.w3.org/2005/Atom\"><title>" . $x($title) . '</title><id>' . $x($self) . '</id>'
            . '<link rel="self" href="' . $x($self) . '"/><updated>' . gmdate(DATE_ATOM, strtotime((string) ($items[0]['published_at'] ?? 'now'))) . '</updated>';
        foreach ($items as $i) {
            $url = $base . $i['notes_url'];
            $xml .= '<entry><title>' . $x($i['app']['name'] . ' ' . $i['version']) . '</title><id>' . $x($url) . '</id><link href="' . $x($url) . '"/>'
                . '<updated>' . gmdate(DATE_ATOM, strtotime((string) ($i['published_at'] ?? 'now'))) . '</updated>'
                . '<summary>' . $x($i['summary'] ?? '') . '</summary><content type="html">' . $x($i['changelog']['html']) . '</content></entry>';
        }
        $xml .= '</feed>';

        return $this->response->setContentType('application/atom+xml; charset=utf-8')->setHeader('Cache-Control', 'public, max-age=300')->setBody($xml);
    }

    public function sitemap()
    {
        $base = rtrim(config('UpdateCenter')->publicBaseUrl, '/');
        $pub  = new PublicCatalogService(null);
        $urls = [$base . '/', $base . '/apps', $base . '/changelog', $base . '/channels'];
        foreach ($pub->apps() as $a) {
            array_push($urls, "{$base}/apps/{$a['slug']}", "{$base}/apps/{$a['slug']}/versions", "{$base}/apps/{$a['slug']}/changelog", "{$base}/apps/{$a['slug']}/requirements");
            foreach ($pub->versions($a['slug'], 30) as $v) {
                if ($v['channel'] === 'STABLE') {
                    $urls[] = $base . $v['notes_url'];
                }
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (array_unique($urls) as $u) {
            $xml .= '<url><loc>' . htmlspecialchars($u, ENT_XML1) . '</loc></url>';
        }

        return $this->response->setContentType('application/xml')->setHeader('Cache-Control', 'public, max-age=3600')->setBody($xml . '</urlset>');
    }

    public function robots()
    {
        $base = rtrim(config('UpdateCenter')->publicBaseUrl, '/');
        $body = config('UpdateCenter')->isProduction()
            ? "User-agent: *\nDisallow: /admin/\nDisallow: /portal/\nDisallow: /api/\nDisallow: /download/\nAllow: /\nSitemap: {$base}/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";

        return $this->response->setContentType('text/plain')->setBody($body);
    }

    public function apiDocs()
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Update Center API</title><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css"></head><body><div id="ui"></div>'
            . '<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>'
            . '<script>SwaggerUIBundle({url:"/api-docs/openapi.yaml",dom_id:"#ui"});</script></body></html>';

        return $this->response->setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self'")->setBody($html);
    }

    public function openapi()
    {
        $file = ROOTPATH . '../openapi/update-center.yaml';
        if (! is_file($file)) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        return $this->response->setContentType('application/yaml')->setBody((string) file_get_contents($file));
    }

    /**
     * Development-only CDN: validates the same HMAC URL signature as the edge
     * worker and serves the LOCAL provider's file with Content-Disposition:
     * attachment. Disabled in production (FR-UC-SEC-07, NFR-C07).
     */
    public function devCdn(string ...$segments)
    {
        if (config('UpdateCenter')->isProduction()) {
            return $this->response->setStatusCode(404);
        }
        $key    = implode('/', array_map('rawurldecode', $segments));
        $exp    = (int) $this->request->getGet('exp');
        $fn     = (string) $this->request->getGet('fn');
        $sig    = (string) $this->request->getGet('sig');
        $signer = new CdnUrlSigner(SecretStore::get(config('UpdateCenter')->cdnSecretRef) ?? hash('sha256', 'dev-cdn|' . SecretStore::appSecret()));
        if (! $signer->verify('/' . $key, $exp, $fn, $sig)) {
            return $this->response->setStatusCode(403)->setContentType('text/plain')->setBody("Signed URL invalid or expired\n");
        }
        if (str_starts_with($key, '_incoming/') || str_starts_with($key, 'bugtrace/symbols/')) {
            return $this->response->setStatusCode(403)->setBody('Forbidden');
        }
        $provider = StorageFactory::fromRow(Catalog::activeStorage());
        if (! $provider instanceof LocalReleaseProvider || ! is_file($provider->path($key))) {
            return $this->response->setStatusCode(404)->setBody('Not found');
        }

        return $this->response->download($provider->path($key), null)->setFileName($fn ?: basename($key))
            ->setContentType('application/octet-stream');
    }

    public function devPart(string $uploadId, string $part)
    {
        if (config('UpdateCenter')->isProduction()) {
            return $this->response->setStatusCode(404);
        }
        $key    = (string) $this->request->getGet('key');
        $signer = new CdnUrlSigner(SecretStore::get(config('UpdateCenter')->cdnSecretRef) ?? hash('sha256', 'dev-cdn|' . SecretStore::appSecret()));
        if (! $signer->verify('/_multipart/' . $uploadId . '/' . $part, (int) $this->request->getGet('exp'), $key, (string) $this->request->getGet('sig'))) {
            return $this->response->setStatusCode(403)->setBody('Signature invalid');
        }
        $provider = StorageFactory::fromRow(Catalog::activeStorage());
        if (! $provider instanceof LocalReleaseProvider) {
            return $this->response->setStatusCode(404);
        }
        $etag = $provider->writePart($uploadId, (int) $part, (string) $this->request->getBody());

        return $this->response->setHeader('ETag', $etag)->setStatusCode(200);
    }
}
