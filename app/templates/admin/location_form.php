<?php $isNew = $loc === null; ?>
<h1><?= $isNew ? 'Nova lokacija' : 'Lokacija ' . $e(strtoupper($loc['grp'])) . '/' . (int)$loc['betnr'] ?></h1>
<form method="post" action="<?= $isNew ? '/admin/locations/new' : '/admin/locations/' . (int)$loc['id'] ?>" class="stack card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <?php if ($isNew): ?>
    <label for="grp">Oznaka koncesije (GRP, npr. ka4)</label>
    <input id="grp" name="grp" type="text" required pattern="[A-Za-z0-9]{2,8}" maxlength="8" list="grps">
    <datalist id="grps"><?php foreach ($concessions as $c): ?><option value="<?= $e($c['grp']) ?>"><?php endforeach; ?></datalist>
    <label for="betnr">Broj lokacije (BETNR)</label>
    <input id="betnr" name="betnr" type="number" required min="1" max="99999">
  <?php else: ?>
    <?php $pub = \Cjenik\Locations::pathSlug((string)$loc['grp']); ?>
    <p class="muted">Javni URL: <a href="/<?= $e($pub) ?>/<?= (int)$loc['betnr'] ?>/">/<?= $e($pub) ?>/<?= (int)$loc['betnr'] ?>/</a></p>
  <?php endif; ?>
  <label for="naziv">Naziv</label>
  <input id="naziv" name="naziv" type="text" required maxlength="200" value="<?= $e($loc['naziv'] ?? '') ?>">
  <label for="pravni_naziv">Pravni naziv (trgovac)</label>
  <input id="pravni_naziv" name="pravni_naziv" type="text" maxlength="200" value="<?= $e($loc['pravni_naziv'] ?? '') ?>">
  <label for="adresa">Adresa prodajnog objekta</label>
  <input id="adresa" name="adresa" type="text" maxlength="300" value="<?= $e($loc['adresa'] ?? '') ?>">
  <label for="oznaka_objekta">Oznaka objekta</label>
  <input id="oznaka_objekta" name="oznaka_objekta" type="text" maxlength="50" value="<?= $e($loc['oznaka_objekta'] ?? '') ?>">
  <label for="oblik_mjesta">Oblik prodajnog mjesta (neobavezno, npr. salon, servis; ulazi u naziv datoteke)</label>
  <input id="oblik_mjesta" name="oblik_mjesta" type="text" maxlength="50" value="<?= $e($loc['oblik_mjesta'] ?? '') ?>">
  <p class="muted">Broj pohrane u nazivu datoteke dodjeljuje sustav automatski (redni broj objave za tu lokaciju i cjenik).</p>
  <p class="muted">Vozila i rezervni dijelovi objavljuju se zajedno u jednom cjeniku „Vozila i proizvodi”. Isključi izvor koji ova lokacija nema (ako nema nijednog, cjenika proizvoda nema); radovi su zaseban cjenik.</p>
  <p class="check">
    <?php foreach (\Cjenik\Locations::SOURCES as $source => $label): ?>
      <input id="f_<?= $e($source) ?>" name="feed_<?= $e($source) ?>" type="checkbox" value="1" <?= $isNew || (int)$loc['feed_' . $source] ? 'checked' : '' ?>><label for="f_<?= $e($source) ?>"><?= $source === 'radovi' ? 'Cjenik' : 'Izvor' ?>: <?= $e($label) ?></label><br>
    <?php endforeach; ?>
    <?php if (!$isNew): ?><input id="ac" name="active" type="checkbox" value="1" <?= (int)$loc['active'] ? 'checked' : '' ?>><label for="ac">Aktivna (neaktivna se ne objavljuje i ne prima upload)</label><?php endif; ?>
  </p>
  <button type="submit">Spremi</button>
</form>
