<h1>Pregled za <?= $e(date('d.m.Y.', strtotime($today))) ?></h1>
<p>
  <span class="badge ok">Stiglo: <?= (int)$stats['ok'] ?></span>
  <span class="badge warn">Preneseno iz jučer: <?= (int)$stats['carry'] ?></span>
  <span class="badge bad">Nedostaje: <?= (int)$stats['missing'] ?></span>
</p>
<form method="post" action="/admin/rebuild"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="secondary" type="submit">Ponovno generiraj javne stranice</button></form>

<table>
  <thead><tr><th>Lokacija</th><th>Feed</th><th>Status</th><th>Zadnji cjenik</th><th>Redaka</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $l = $r['loc']; ?>
    <tr>
      <td><a href="/admin/locations/<?= (int)$l['id'] ?>"><?= $e(strtoupper($l['grp'])) ?>/<?= (int)$l['betnr'] ?></a> <?= $e($l['naziv']) ?></td>
      <td><?= $e($r['feed']) ?></td>
      <td>
        <?php if ($r['status'] === 'ok'): ?><span class="badge ok">stiglo <?= $e(substr((string)$r['today']['created_at'], 11, 5)) ?> UTC</span>
        <?php elseif ($r['status'] === 'carry'): ?><span class="badge warn">preneseno iz jučer</span>
        <?php else: ?><span class="badge bad">nedostaje</span><?php endif; ?>
      </td>
      <td><?= $r['last'] ? $e(date('d.m.Y.', strtotime((string)$r['last']))) : '–' ?></td>
      <td><?= $r['today'] ? (int)$r['today']['rows_count'] : '–' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
