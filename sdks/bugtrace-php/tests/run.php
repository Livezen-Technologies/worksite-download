<?php

/**
 * Self-contained checks (no PHPUnit): php tests/run.php
 * AC-C63: server exceptions are reported without request bodies or SQL parameters.
 */

declare(strict_types=1);

require __DIR__ . '/../src/BugTraceReporter.php';

use Modules\Core\Libraries\BugTraceReporter;

$fail = 0;
$ok   = static function (bool $cond, string $what) use (&$fail): void {
    echo ($cond ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fail += $cond ? 0 : 1;
};

$spool = sys_get_temp_dir() . '/bt-test-' . getmypid() . '.spool';
@unlink($spool);
$sent = [];
$status = 202;
$transport = static function (string $url, array $headers, string $body) use (&$sent, &$status): int {
    $sent[] = ['url' => $url, 'headers' => $headers, 'body' => json_decode((string) gzdecode($body), true)];

    return $status;
};
$cfg = ['key' => 'pk_server_prod_0000000001', 'release' => '3.4.0+34012', 'tenant_secret' => 'tenant-secret', 'installation_id' => 'inst_01', 'edition' => 'BUSINESS', 'root_path' => __DIR__, 'spool_file' => $spool, 'endpoint' => 'https://uc.test'];

function saveOrder(string $email, string $password): void
{
    throw new RuntimeException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$email}' for key 'users.email' — INSERT INTO users (email, password, salary) VALUES ('{$email}', '{$password}', 125000.50)", 0, new InvalidArgumentException('token=abcdef123456 for ' . $email));
}

$r = new BugTraceReporter($cfg + ['policy' => ['policy' => 'CRASHES_AND_ERRORS', 'server_reporting' => true]], null, null, $transport);
try {
    saveOrder('kasun@example.lk', 'S3cr3t!');
} catch (Throwable $e) {
    $id = $r->capture($e, ['route' => 'orders/(:num)?debug=1', 'method' => 'POST', 'area' => 'orders', 'user_id' => 42]);
}
$ok(is_string($id) && strlen($id) === 32, 'capture returns an event id');
$ok($r->queued() === 1, 'event queued (spool fallback without Redis)');
$res = $r->flush();
$ok($res['sent'] === 1 && count($sent) === 1, 'flush sends one batch');
$wire = json_encode($sent[0]['body']);
foreach (['kasun@example.lk', 'S3cr3t!', '125000.50', 'abcdef123456', 'debug=1', '"args"', '"42"'] as $leak) {
    $ok(! str_contains($wire, $leak), "no leak of {$leak}");
}
$ev = $sent[0]['body']['events'][0];
$ok($ev['type'] === 'crash' && $ev['platform'] === 'server', 'unhandled exception → crash event');
$ok(str_starts_with($ev['user_ref'], 'u_hmac:') && str_starts_with($ev['installation_ref'], 'inst_hmac:'), 'identifiers are HMAC pseudonyms');
$ok(str_contains($ev['exception'][1]['value'], "Duplicate entry '?'"), 'SQL literals replaced');
$ok($ev['contexts']['app']['route'] === 'orders/(:num)', 'route without query');
$ok(end($ev['exception'][1]['stacktrace']['frames'])['function'] === 'saveOrder', 'innermost frame names its function');
$ok($sent[0]['headers']['X-BT-Key'] === 'pk_server_prod_0000000001' && $sent[0]['headers']['Content-Encoding'] === 'gzip', 'key + gzip headers');
$ok(! isset($sent[0]['headers']['Cookie']) && ! isset($sent[0]['headers']['Authorization']), 'no tenant request headers forwarded');

// Update Center down → requeued, then delivered.
$status = 503;
$r->capture(new RuntimeException('boom'));
$res = $r->flush();
$ok($res['requeued'] === 1 && $r->queued() === 1, 'requeued when the Update Center is unavailable');
$status = 202;
$ok($r->flush()['sent'] === 1, 'delivered on the next flush');

// Policy from the TPT claim.
$tpt = rtrim(strtr(base64_encode(json_encode(['payload' => ['bugtrace' => ['policy' => 'OFF', 'server_reporting' => true]]])), '+/', '-_'), '=');
$off = new BugTraceReporter($cfg, null, static fn () => $tpt, $transport);
$ok($off->capture(new RuntimeException('x')) === null && $off->queued() === 0, 'policy OFF → nothing captured');
$noSrv = new BugTraceReporter($cfg + ['policy' => ['policy' => 'CRASHES_AND_ERRORS', 'server_reporting' => false]], null, null, $transport);
$ok($noSrv->capture(new RuntimeException('x')) === null, 'server_reporting off → nothing captured');
$crashOnly = new BugTraceReporter($cfg + ['policy' => ['policy' => 'CRASHES_ONLY', 'server_reporting' => true]], null, null, $transport);
$ok($crashOnly->capture(new RuntimeException('x'), ['handled' => true]) === null, 'CRASHES_ONLY drops handled errors');

@unlink($spool);
echo $fail === 0 ? "\nAll checks passed\n" : "\n{$fail} check(s) failed\n";
exit($fail === 0 ? 0 : 1);
