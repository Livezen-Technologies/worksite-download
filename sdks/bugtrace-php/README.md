# worksuite/bugtrace-php

This is the reference Bug Trace reporter for the WorkSuite tenant server (CodeIgniter 4,
PHP 8.2+). It implements SRS §18.7, WF-C34, FR-BT-SDK-04 and AC-C63.

* `BugTraceReporter::capture()` never slows a request. It builds a scrubbed event and pushes
  it onto a Redis list (`bugtrace:queue`, capped at 1,000 entries). Without Redis it appends
  to a spool file (capped at 5 MB).
* `php spark bugtrace:flush` runs from cron every minute. It sends gzip batches of 50 events
  to `POST /api/v1/bugtrace/envelope` with the project key and the tenant's current TPT.
  If the Update Center is unavailable, events are requeued and retried up to 5 times.
* **Never sent:** request bodies, query strings, headers, cookies, function arguments, local
  variables, or SQL bound values. Quoted literals and numbers in SQL error messages become `?`,
  and e-mail addresses, tokens, passwords, NIC numbers and phone numbers become `[Filtered]`.
* User and installation identifiers are HMAC pseudonyms (`u_hmac:…`, `inst_hmac:…`) keyed by
  the tenant secret. The Update Center never learns tenant user ids.
* The reporter follows the tenant policy from the TPT `bugtrace` claim. `OFF` or
  `server_reporting: false` means nothing is captured, and queued events are dropped at flush.
  `CRASHES_ONLY` keeps unhandled exceptions and fatal errors only.

## Install (WorkSuite repo)

Copy `src/` to `app/Modules/Core/Libraries/` (namespace `Modules\Core\Libraries`), or require
the package. Then register the pieces below.

```php
// app/Config/Services.php
public static function bugtrace(bool $getShared = true): \Modules\Core\Libraries\BugTraceReporter
{
    if ($getShared) {
        return static::getSharedInstance('bugtrace');
    }
    $redis = new \Redis();
    $redis->connect(env('redis.host', '127.0.0.1'));

    return new \Modules\Core\Libraries\BugTraceReporter([
        'endpoint'        => 'https://download.worksuitecloud.com',
        'key'             => env('bugtrace.key'),            // pk_server_prod_…
        'release'         => \Config\App::VERSION_STRING,     // "3.4.0+34012" (matches the Update Center release)
        'environment'     => ENVIRONMENT,
        'installation_id' => setting('license.installation_id'),
        'tenant_secret'   => env('encryption.key'),           // HMAC key for pseudonyms
        'edition'         => setting('license.edition'),
        'root_path'       => ROOTPATH,                        // frames are sent relative to it
    ], $redis, static fn () => service('tenantPolicy')->currentToken());
}
```

```php
// app/Config/Exceptions.php — web requests (500s)
public function handler(int $statusCode, \Throwable $exception): \CodeIgniter\Debug\ExceptionHandlerInterface
{
    return new \Modules\Core\Libraries\BugTraceExceptionHandler(new \CodeIgniter\Debug\ExceptionHandler($this), service('bugtrace'));
}
```

```php
// app/Config/Events.php — fatal errors and failed spark jobs
Events::on('pre_system', static fn () => service('bugtrace')->registerFatalHandler());
// in the job runner's catch block:
service('bugtrace')->capture($e, ['command' => 'mail:fetch', 'area' => 'mail']);
```

Cron: `* * * * * php /var/www/worksuite/spark bugtrace:flush` (see `src/Commands/BugTraceFlush.php`).

The context passed to `capture()` accepts:

* `area`: the module.
* `route`: the route **pattern**, never the URL.
* `method`, `command`, `user_id` (pseudonymised before queueing), `handled`, `fatal`, `tags`.

## Tests

```sh
php tests/run.php
```

The checks run without PHPUnit, Redis or network access. They cover no leaks of e-mail
addresses, passwords, SQL values, tokens, query strings or arguments; HMAC identifiers; the
wire format; requeue on outage; and the OFF, `server_reporting` and `CRASHES_ONLY` policies.
