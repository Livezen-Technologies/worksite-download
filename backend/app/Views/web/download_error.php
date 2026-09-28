<?php
/**
 * @var int         $status
 * @var string      $title
 * @var string      $message
 * @var string|null $appName
 * @var string|null $appUrl
 * @var array       $alternatives list of [label, url]
 * @var string|null $retryAfter
 */
$e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($title) ?> · WorkSuite Downloads</title>
<style>
  :root { --navy:#0F1B3D; --brand:#3B5BDB; --ground:#F4F5F9; --ink:#1B2240; --muted:#5B6386; }
  * { box-sizing: border-box; }
  body { margin:0; font:16px/1.55 "Plus Jakarta Sans", system-ui, -apple-system, "Segoe UI", sans-serif; background:var(--ground); color:var(--ink); }
  header { background:var(--navy); color:#fff; padding:14px 24px; font-weight:700; }
  header a { color:#fff; text-decoration:none; }
  main { max-width:640px; margin:48px auto; padding:0 16px; }
  .card { background:#fff; border-radius:14px; padding:32px; box-shadow:0 1px 3px rgba(15,27,61,.08); }
  h1 { margin:0 0 8px; font-size:24px; }
  .code { font:13px "JetBrains Mono", ui-monospace, monospace; color:var(--muted); }
  a.btn { display:inline-block; margin:8px 8px 0 0; padding:10px 16px; border-radius:10px; background:var(--brand); color:#fff; text-decoration:none; font-weight:600; }
  a.alt { background:#E8ECFB; color:var(--navy); }
  ul { padding-left:18px; }
</style>
</head>
<body>
<header><a href="/">WorkSuite Downloads</a></header>
<main>
  <div class="card" role="alert">
    <p class="code">HTTP <?= (int) $status ?></p>
    <h1><?= $e($title) ?></h1>
    <p><?= $e($message) ?></p>
    <?php if (! empty($retryAfter)): ?><p>Please try again in a few minutes.</p><?php endif ?>
    <?php if (! empty($alternatives)): ?>
      <p>Other downloads:</p>
      <ul><?php foreach ($alternatives as [$label, $url]): ?><li><a href="<?= $e($url) ?>"><?= $e($label) ?></a></li><?php endforeach ?></ul>
    <?php endif ?>
    <?php if (! empty($appUrl)): ?><a class="btn" href="<?= $e($appUrl) ?>"><?= $e($appName ?? 'Back to downloads') ?></a><?php endif ?>
    <a class="btn alt" href="/">All downloads</a>
  </div>
</main>
</body>
</html>
