<h1>Cjenici</h1>
<p class="lead">Cjenici se objavljuju na posebnoj adresi svake koncesije i prodajnog mjesta. Upotrijebite poveznicu koju ste dobili od koncesije.</p>
<?php if (!empty($operator['name'])): ?>
  <p class="muted">Održava: <?= $e($operator['name']) ?><?= !empty($operator['contact']) ? ' · ' . $e($operator['contact']) : '' ?></p>
<?php endif; ?>
