<h1>Format CSV datoteka</h1>
<p class="lead">Datoteke su kodirane u UTF-8, razdjelnik je točka-zarez (<code>;</code>), prvi redak je zaglavlje, a iznosi koriste decimalnu točku (npr. <code>1234.50</code>) i izraženi su u EUR.</p>

<?php
$types = ['text' => 'tekst', 'money' => 'iznos (decimalna točka)', 'decimal' => 'broj (decimalna točka)', 'yesno' => 'da / ne', 'avail' => 'dostupno / nedostupno', 'barcode' => 'barkod (8–14 znamenki)'];
$groups = [
    'proizvod' => ['Vozila i proizvodi', 'Vozila i rezervni dijelovi u istoj datoteci (ista struktura).'],
    'usluga' => ['Usluge', 'Radovi.'],
];
foreach ($groups as $kind => [$title, $desc]):
    $names = [];
    $columns = null;
    foreach ($feeds as $key => $f) {
        if ($f['kind'] === $kind) {
            $names[] = $key;
            $columns = $f['columns'];
        }
    }
    if ($columns === null) { continue; }
?>
<section class="card">
  <h2><?= $e($title) ?> <small class="muted">cjenici: <?= $e(implode(', ', $names)) ?></small></h2>
  <p class="muted"><?= $e($desc) ?></p>
  <table>
    <thead><tr><th>Stupac</th><th>Obavezno</th><th>Vrsta</th></tr></thead>
    <tbody>
    <?php foreach ($columns as $name => [$type, $required, $max]): ?>
      <tr><td><code><?= $e($name) ?></code></td><td><?= $required ? 'da' : 'ne' ?></td><td><?= $e($types[$type] ?? $type) ?><?= $max ? ' (max ' . (int)$max . ')' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endforeach; ?>

<section class="card">
  <h2>Napomene</h2>
  <ul>
    <li><code>bazna_cijena</code> i <code>sidrena_cijena</code> su cijene na dan 10.9.2026., <code>maloprodajna_cijena</code> je cijena koja trenutno vrijedi (s popustom), a <code>najniza_cijena_30_dana</code> je najniža cijena u zadnjih 30 dana.</li>
    <li>Sve cijene su s PDV-om i PPMV-om. Objavljuju se samo artikli na zalihi.</li>
    <li>Ako je <code>posebni_oblik_prodaje</code> = <code>da</code>, upisuje se i naziv posebnog oblika prodaje.</li>
    <li>Stalni URL najnovijeg cjenika: <code>/&lt;koncesija&gt;/&lt;broj lokacije&gt;/&lt;proizvodi|radovi&gt;/latest.csv</code></li>
    <li>Arhiva zadnjih 30 dana: <code>…/GGGG-MM-DD.csv</code></li>
  </ul>
</section>
