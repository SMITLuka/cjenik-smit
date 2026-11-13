<h1>Ručni upload cjenika</h1>
<p class="muted">Za hitne ispravke ili ako agent ne radi. CSV prolazi istu validaciju kao i automatski upload. Format: <a href="/format.html">opis stupaca</a>.</p>
<form method="post" action="/admin/upload" enctype="multipart/form-data" class="stack card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="location_id">Lokacija</label>
  <select id="location_id" name="location_id" required>
    <?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= $e(strtoupper($l['grp'])) ?>/<?= (int)$l['betnr'] ?> – <?= $e($l['naziv']) ?></option><?php endforeach; ?>
  </select>
  <label for="feed">Feed</label>
  <select id="feed" name="feed"><?php foreach (\Cjenik\CsvSpec::FEEDS as $feedName => $feedDef): ?><option value="<?= $e($feedName) ?>"><?= $e($feedName) ?> (<?= $e($feedDef['label']) ?>)</option><?php endforeach; ?></select>
  <label for="date">Za dan</label>
  <select id="date" name="date"><option value="today">danas</option><option value="yesterday">jučer</option></select>
  <label for="file">CSV datoteka</label>
  <input id="file" name="file" type="file" accept=".csv,text/csv" required>
  <button type="submit">Provjeri i objavi</button>
</form>
