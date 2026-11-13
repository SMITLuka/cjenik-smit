<h1><?= $e($concession['naziv']) ?></h1>
<?php if (!empty($concession['web_url'])): ?>
  <p><a href="<?= $e($concession['web_url']) ?>" rel="noopener"><?= $e($concession['web_url']) ?></a></p>
<?php endif; ?>

<h2>Prodajna mjesta</h2>
<ul class="plain">
<?php foreach ($locations as $l): ?>
  <li><a href="/<?= $e($slug) ?>/<?= (int)$l['betnr'] ?>/"><?= $e($l['naziv']) ?></a><?= !empty($l['adresa']) ? ' <span class="muted">– ' . $e($l['adresa']) . '</span>' : '' ?></li>
<?php endforeach; ?>
</ul>
