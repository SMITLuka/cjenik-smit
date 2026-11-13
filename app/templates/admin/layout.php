<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="robots" content="noindex,nofollow">
<title>Admin – Cjenici</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site">
  <div class="wrap">
    <a class="brand" href="/admin/">Cjenici · admin</a>
    <?php if ($auth): ?>
    <nav aria-label="Admin navigacija">
      <a href="/admin/">Pregled</a>
      <a href="/admin/locations">Lokacije</a>
      <a href="/admin/concessions">Koncesije</a>
      <a href="/admin/keys">Ključevi</a>
      <a href="/admin/upload">Ručni upload</a>
      <a href="/admin/import">Uvoz</a>
      <a href="/admin/audit">Audit</a>
      <a href="/admin/password">Lozinka</a>
    </nav>
    <form method="post" action="/admin/logout" style="margin:0">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="secondary" type="submit" style="margin:0"><?= $e($username) ?> · Odjava</button>
    </form>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
<?php foreach ($flash as [$type, $msg]): ?>
  <p class="<?= $type === 'ok' ? 'ok' : 'err' ?>" role="status"><?= $e($msg) ?></p>
<?php endforeach; ?>
<?= $content ?>
</main>
</body>
</html>
