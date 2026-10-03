<?php

namespace App\Controllers;

use App\Libraries\SecretStore;
use App\Services\MetricsService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * GET /metrics — Prometheus scrape endpoint (§13.4). Requires the bearer token
 * referenced by `updatecenter.metricsTokenRef`; without one configured the
 * endpoint does not exist (404). nginx additionally keeps it off the public
 * site.
 */
class MetricsController extends Controller
{
    public function index(): ResponseInterface
    {
        $token = SecretStore::get(config('UpdateCenter')->metricsTokenRef);
        if ($token === null || $token === '') {
            return $this->response->setStatusCode(404);
        }
        $given = preg_replace('/^Bearer\s+/i', '', $this->request->getHeaderLine('Authorization'));
        if (! hash_equals($token, (string) $given)) {
            return $this->response->setStatusCode(401)->setHeader('WWW-Authenticate', 'Bearer');
        }

        return $this->response->setStatusCode(200)
            ->setContentType('text/plain; version=0.0.4; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setBody((new MetricsService())->render());
    }
}
