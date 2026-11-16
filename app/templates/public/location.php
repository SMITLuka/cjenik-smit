<p class="back"><a href="/<?= $e($slug) ?>/">← <?= $e($concession['naziv']) ?></a></p>
<?= \Cjenik\View::render('public/location_block', [
    'level' => 1, 'slug' => $slug, 'loc' => $loc, 'feeds' => $feeds, 'today' => $today, 'baseUrl' => $baseUrl,
]) ?>
<p class="muted"><a href="/<?= $e($slug) ?>/format.html">Opis stupaca (format CSV datoteka)</a></p>
