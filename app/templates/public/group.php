<h1><?= $e($concession['naziv']) ?></h1>
<?php if (!empty($concession['web_url'])): ?>
  <p><a href="<?= $e($concession['web_url']) ?>" rel="noopener"><?= $e($concession['web_url']) ?></a></p>
<?php endif; ?>

<?php if (count($items) > 1): ?>
  <p class="muted">Prodajna mjesta:
  <?php foreach ($items as $i => $it): ?><?= $i > 0 ? ' · ' : '' ?><a href="#lok-<?= (int)$it['loc']['betnr'] ?>"><?= $e($it['loc']['naziv']) ?></a><?php endforeach; ?>
  </p>
<?php endif; ?>

<?php foreach ($items as $it): ?>
  <?= \Cjenik\View::render('public/location_block', [
      'level' => 2, 'slug' => $slug, 'loc' => $it['loc'], 'feeds' => $it['feeds'], 'today' => $today, 'baseUrl' => $baseUrl,
  ]) ?>
<?php endforeach; ?>

<p class="muted"><a href="/<?= $e($slug) ?>/format.html">Opis stupaca (format CSV datoteka)</a></p>
