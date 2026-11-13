<h1>Koncesije</h1>
<p class="muted">Naziv, web adresa i oznaka u adresi prikazuju se/koriste na javnim stranicama. Interna oznaka (npr. KA7) ostaje ista i koristi je agent.</p>
<?php foreach ($concessions as $c): $path = \Cjenik\Locations::pathSlug((string)$c['grp']); ?>
<form method="post" action="/admin/concessions" class="stack card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="grp" value="<?= $e($c['grp']) ?>">
  <h2><?= $e(strtoupper($c['grp'])) ?> <small class="muted">javna adresa: <a href="/<?= $e($path) ?>/">/<?= $e($path) ?>/</a></small></h2>
  <label for="n<?= $e($c['grp']) ?>">Naziv</label>
  <input id="n<?= $e($c['grp']) ?>" name="naziv" type="text" required maxlength="200" value="<?= $e($c['naziv']) ?>">
  <label for="s<?= $e($c['grp']) ?>">Oznaka u adresi (npr. autocentarroca; prazno = <?= $e($c['grp']) ?>)</label>
  <input id="s<?= $e($c['grp']) ?>" name="slug" type="text" maxlength="60" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= $e($c['slug'] ?? '') ?>">
  <label for="u<?= $e($c['grp']) ?>">Web adresa koncesije (neobavezno)</label>
  <input id="u<?= $e($c['grp']) ?>" name="web_url" type="text" maxlength="300" value="<?= $e($c['web_url'] ?? '') ?>" placeholder="https://">
  <p class="muted">Promjena oznake u adresi odmah prestaje vrijediti stari URL (404) — javi koncesiji novu adresu.</p>
  <button type="submit">Spremi</button>
</form>
<?php endforeach; ?>
<?php if (!$concessions): ?><p>Nema koncesija. Dodaju se automatski pri unosu lokacije.</p><?php endif; ?>
