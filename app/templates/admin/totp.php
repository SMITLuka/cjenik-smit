<h1><?= $enroll ? 'Postavi dvofaktorsku prijavu' : 'Kod za prijavu' ?></h1>
<?php if (!empty($error)): ?><p class="err" role="alert"><?= $e($error) ?></p><?php endif; ?>
<?php if ($enroll && $secret): ?>
  <div class="card">
    <p>Dodaj račun u aplikaciju za autentikaciju (Google/Microsoft Authenticator, Aegis, 1Password…) tako da ručno upišeš ovaj ključ (vrsta: vremenski/TOTP):</p>
    <p class="secret"><?= $e($secret) ?></p>
    <p class="muted">Ili otvori ovaj link na uređaju s aplikacijom: <code><?= $e($uri) ?></code></p>
    <p>Zatim upiši šesteroznamenkasti kod koji aplikacija prikazuje da potvrdiš.</p>
  </div>
<?php endif; ?>
<form method="post" action="/admin/totp" class="stack card" autocomplete="off">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="code">Šesteroznamenkasti kod</label>
  <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" required autofocus autocomplete="one-time-code">
  <button type="submit">Prijavi se</button>
</form>
