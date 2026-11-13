<!doctype html>
<html lang="hr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?> – Cjenici</title>
<meta name="color-scheme" content="light">
<meta name="robots" content="index,follow">
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site">
  <div class="wrap">
    <?php if (!empty($brandHref)): ?><a class="brand" href="<?= $e($brandHref) ?>"><?= $e($brand) ?></a><?php else: ?><span class="brand"><?= $e($brand) ?></span><?php endif; ?>
    <?php if (!empty($nav)): ?><nav aria-label="Navigacija"><?php foreach ($nav as [$label, $href]): ?><a href="<?= $e($href) ?>"><?= $e($label) ?></a> <?php endforeach; ?></nav><?php endif; ?>
  </div>
</header>
<main class="wrap">
<?= $content ?>
</main>
<footer class="site">
  <div class="wrap">
    <p>Cjenici se objavljuju u strojno čitljivom CSV obliku i dostupni su 30 dana. Stranica osvježena: <?= $e($generated) ?></p>
    <?php if (!empty($operator['name'])): ?><p>Održava: <?= $e($operator['name']) ?><?= !empty($operator['contact']) ? ' · ' . $e($operator['contact']) : '' ?></p><?php endif; ?>
  </div>
</footer>
</body>
</html>
