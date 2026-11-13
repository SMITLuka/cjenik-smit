<h1>Prijava</h1>
<?php if (!empty($error)): ?><p class="err" role="alert"><?= $e($error) ?></p><?php endif; ?>
<form method="post" action="/admin/login" class="stack card" autocomplete="off">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="username">Korisničko ime</label>
  <input id="username" name="username" type="text" required autofocus autocomplete="username">
  <label for="password">Lozinka</label>
  <input id="password" name="password" type="password" required autocomplete="current-password">
  <button type="submit">Dalje</button>
</form>
