<h1>Uvoz</h1>
<p class="muted">CSV s razdjelnikom <code>;</code> (UTF-8). Cijeli uvoz je jedna cjelina: ako je u bilo kojem retku greška, ništa se ne sprema. Prazna ćelija ne mijenja postojeću vrijednost.</p>

<form method="post" action="/admin/import" enctype="multipart/form-data" class="stack card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="kind" value="locations">
  <h2>Lokacije</h2>
  <p class="muted">Obavezno: <code>grp;betnr;naziv</code>. Neobavezno: <code>pravni_naziv;adresa;oblik_mjesta;oznaka_objekta;dijelovi;vozila;radovi</code> (zadnja tri su izvori cjenika, da/ne: vozila i dijelovi objavljuju se zajedno u cjeniku „Vozila i proizvodi”, radovi su zaseban cjenik). Postojeće lokacije se ažuriraju upisanim stupcima.</p>
  <label for="file1">CSV datoteka</label>
  <input id="file1" name="file" type="file" accept=".csv,text/csv" required>
  <button type="submit">Uvezi lokacije</button>
</form>

<form method="post" action="/admin/import" enctype="multipart/form-data" class="stack card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="kind" value="concessions">
  <h2>Koncesije</h2>
  <p class="muted">Obavezno: <code>grp;naziv</code>. Neobavezno: <code>slug;web_url</code>. <strong>Promjena sluga odmah gasi stari URL.</strong></p>
  <label for="file2">CSV datoteka</label>
  <input id="file2" name="file" type="file" accept=".csv,text/csv" required>
  <button type="submit">Uvezi koncesije</button>
</form>
