<h1>API ključevi (ingest)</h1>
<?php if (!empty($new)): ?>
  <div class="card" role="alert">
    <h2>Novi ključ – spremi ga sada</h2>
    <p>Tajna se prikazuje samo jednom i ne može se ponovno pročitati.</p>
    <p>Key ID:</p><p class="secret"><?= $e($new['key_id']) ?></p>
    <p>Tajna:</p><p class="secret"><?= $e($new['secret']) ?></p>
  </div>
<?php endif; ?>

<form method="post" action="/admin/keys" class="stack card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <h2>Novi ključ</h2>
  <label for="scope">Opseg</label>
  <select id="scope" name="scope">
    <option value="global">Sve lokacije (agent)</option>
    <option value="group">Jedna koncesija</option>
    <option value="location">Jedna lokacija</option>
  </select>
  <label for="grp">Koncesija (za opseg „Jedna koncesija”)</label>
  <select id="grp" name="grp"><option value="">–</option><?php foreach ($concessions as $c): ?><option value="<?= $e($c['grp']) ?>"><?= $e(strtoupper($c['grp'])) ?> – <?= $e($c['naziv']) ?></option><?php endforeach; ?></select>
  <label for="location_id">Lokacija (za opseg „Jedna lokacija”)</label>
  <select id="location_id" name="location_id"><option value="">–</option><?php foreach ($locations as $l): ?><option value="<?= (int)$l['id'] ?>"><?= $e(strtoupper($l['grp'])) ?>/<?= (int)$l['betnr'] ?> – <?= $e($l['naziv']) ?></option><?php endforeach; ?></select>
  <label for="label">Oznaka (npr. „agent VPN”)</label>
  <input id="label" name="label" type="text" required maxlength="100">
  <button type="submit">Izdaj ključ</button>
</form>

<table>
  <thead><tr><th>Key ID</th><th>Oznaka</th><th>Opseg</th><th>Zadnje korišten (UTC)</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($keys as $k): ?>
    <tr>
      <td><code><?= $e($k['key_id']) ?></code></td>
      <td><?= $e($k['label']) ?></td>
      <td><?= $e($k['scope']) ?><?= $k['grp'] ? ' ' . $e(strtoupper($k['grp'])) : '' ?><?= $k['location_id'] ? ' #' . (int)$k['location_id'] : '' ?></td>
      <td><?= $e($k['last_used_at'] ?? '–') ?></td>
      <td>
        <?php if ($k['revoked_at']): ?><span class="badge bad">opozvan</span>
        <?php else: ?>
          <form method="post" action="/admin/keys" style="margin:0">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="revoke"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>">
            <button class="danger" type="submit" style="margin:0">Opozovi</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
