<p class="back"><a href="/<?= $e($slug) ?>/">← <?= $e($concession['naziv']) ?></a></p>
<h1><?= $e($loc['naziv']) ?></h1>
<?php if (!empty($loc['adresa'])): ?><p><?= $e($loc['adresa']) ?></p><?php endif; ?>

<h2>Cjenici</h2>
<table>
  <?php foreach ($feeds as $feed => $f):
      $latest = $f['uploads'][0] ?? null;
      $base = '/' . $slug . '/' . (int)$loc['betnr'] . '/' . $feed;
  ?>
    <tr>
      <td><?= $e($f['label']) ?></td>
      <?php if ($latest === null): ?>
        <td class="muted">još nije objavljen</td><td></td>
      <?php else: ?>
        <td><?= $e(date('d.m.Y.', strtotime($latest['price_date']))) ?></td>
        <td><a class="btn small" href="<?= $e($base) ?>/latest.csv" download="<?= $e($latest['download']) ?>">Preuzmi CSV</a></td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
</table>

<?php foreach ($feeds as $feed => $f):
    $latest = $f['uploads'][0] ?? null;
    if ($latest === null) { continue; }
    if ($latest['price_date'] !== $today): ?>
  <p class="notice"><?= $e($f['label']) ?>: najnoviji dostupni cjenik je od <?= $e(date('d.m.Y.', strtotime($latest['price_date']))) ?>.</p>
<?php elseif ($latest['source'] === 'carryover'): ?>
  <p class="notice"><?= $e($f['label']) ?>: danas nije stigao novi cjenik pa je preuzet cjenik iz prethodnog dana.</p>
<?php endif; endforeach; ?>

<h2>Arhiva (30 dana)</h2>
<?php $anyArchive = false; foreach ($feeds as $feed => $f):
    $ups = $f['uploads'];
    if (!$ups) { continue; }
    $anyArchive = true;
    $base = '/' . $slug . '/' . (int)$loc['betnr'] . '/' . $feed;
?>
  <details>
    <summary><?= $e($f['label']) ?> (<?= count($ups) ?>)</summary>
    <ul class="plain">
    <?php foreach ($ups as $u): ?>
      <li><?= $e(date('d.m.Y.', strtotime($u['price_date']))) ?><?= $u['source'] === 'carryover' ? ' <span class="muted">(prenesen)</span>' : '' ?> –
        <a href="<?= $e($base . '/' . $u['file_name']) ?>" download="<?= $e($u['download']) ?>">CSV</a>
        <span class="muted">(<?= (int)$u['rows_count'] ?> redaka)</span></li>
    <?php endforeach; ?>
    </ul>
  </details>
<?php endforeach; if (!$anyArchive): ?>
  <p class="muted">Još nema objavljenih cjenika.</p>
<?php endif; ?>

<h2>Automatsko preuzimanje</h2>
<ul class="plain">
<?php foreach ($feeds as $feed => $f): ?>
  <li><?= $e($f['label']) ?>: <code><?= $e($baseUrl . '/' . $slug . '/' . (int)$loc['betnr'] . '/' . $feed) ?>/latest.csv</code></li>
<?php endforeach; ?>
</ul>
<p class="muted"><a href="/<?= $e($slug) ?>/format.html">Opis stupaca (format CSV datoteka)</a></p>
