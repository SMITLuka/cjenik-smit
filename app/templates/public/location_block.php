<?php
// Zajednicki blok jedne lokacije: koristi se na stranici lokacije (naslov h1) i na stranici koncesije (naslov h2),
// da se cjenici svih lokacija vide odmah, bez ulaska u podstranice. $level: 1 = stranica lokacije, 2 = stranica koncesije.
$h = $level === 1 ? 'h1' : 'h2';
$s = $level === 1 ? 'h2' : 'h3';
$locBase = '/' . $slug . '/' . (int)$loc['betnr'];
?>
<section class="lokacija" id="lok-<?= (int)$loc['betnr'] ?>">
<<?= $h ?>><?= $e($loc['naziv']) ?></<?= $h ?>>
<?php if (!empty($loc['adresa'])): ?><p><?= $e($loc['adresa']) ?></p><?php endif; ?>

<<?= $s ?>>Cjenici</<?= $s ?>>
<table>
  <?php foreach ($feeds as $feed => $f):
      $latest = $f['uploads'][0] ?? null;
      $base = $locBase . '/' . $feed;
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

<<?= $s ?>>Arhiva (30 dana)</<?= $s ?>>
<?php $anyArchive = false; foreach ($feeds as $feed => $f):
    $ups = $f['uploads'];
    if (!$ups) { continue; }
    $anyArchive = true;
    $base = $locBase . '/' . $feed;
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

<<?= $s ?>>Automatsko preuzimanje</<?= $s ?>>
<ul class="plain">
<?php foreach ($feeds as $feed => $f): ?>
  <li><?= $e($f['label']) ?>: <code><?= $e($baseUrl . $locBase . '/' . $feed) ?>/latest.csv</code></li>
<?php endforeach; ?>
</ul>
</section>
