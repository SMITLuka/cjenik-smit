<h1>Promjena lozinke</h1>
<form method="post" action="/admin/password" class="stack card" autocomplete="off">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="current">Trenutna lozinka</label>
  <input id="current" name="current" type="password" required autocomplete="current-password">
  <label for="new">Nova lozinka (min. 12 znakova)</label>
  <input id="new" name="new" type="password" required minlength="12" autocomplete="new-password">
  <label for="new2">Ponovi novu lozinku</label>
  <input id="new2" name="new2" type="password" required minlength="12" autocomplete="new-password">
  <button type="submit">Promijeni</button>
</form>
