<h1>Lokacije <small class="muted">(<?= count($locations) ?>)</small></h1>
<p><a class="btn" href="/admin/locations/new">Nova lokacija</a> <a href="/admin/import">Uvoz iz CSV-a</a></p>
<table>
  <thead><tr><th>Oznaka</th><th>Naziv</th><th>Adresa</th><th>Feedovi</th><th>Aktivna</th></tr></thead>
  <tbody>
  <?php foreach ($locations as $l): ?>
    <tr>
      <td><a href="/admin/locations/<?= (int)$l['id'] ?>"><?= $e(strtoupper($l['grp'])) ?>/<?= (int)$l['betnr'] ?></a></td>
      <td><?= $e($l['naziv']) ?></td>
      <td><?= $e($l['adresa'] ?? '') ?></td>
      <td><?= $e(implode(', ', \Cjenik\Locations::feeds($l)) ?: '–') ?></td>
      <td><?= (int)$l['active'] ? 'da' : '<span class="muted">ne</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
