<h1>Audit log <small class="muted">(zadnjih 300)</small></h1>
<table>
  <thead><tr><th>Vrijeme (UTC)</th><th>Tko</th><th>IP</th><th>Radnja</th><th>Detalj</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td><?= $e($r['ts']) ?></td><td><?= $e($r['actor']) ?></td><td><?= $e($r['ip']) ?></td><td><?= $e($r['action']) ?></td><td><?= $e($r['detail'] ?? '') ?></td></tr>
  <?php endforeach; ?>
  </tbody>
</table>
