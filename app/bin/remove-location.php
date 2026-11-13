<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

// php app/bin/remove-location.php ki2/1 [--yes]
// TRAJNO brise lokaciju, njezine objavljene cjenike i arhivu. Ako koncesija ostane prazna, brise i nju.
$arg = (string)($argv[1] ?? '');
[$g, $b] = array_pad(explode('/', $arg), 2, '');
$loc = ($g !== '' && ctype_digit($b)) ? \Cjenik\Locations::find(strtolower($g), (int)$b) : null;
if ($loc === null) {
    fwrite(STDERR, "Upotreba: php remove-location.php <grp/betnr> [--yes]   (npr. ki2/1)\n");
    exit(1);
}
if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "Ovo trajno brise {$loc['grp']}/{$loc['betnr']} ({$loc['naziv']}) i sve njezine cjenike. Ponovi s --yes za potvrdu.\n");
    exit(1);
}
$r = \Cjenik\Locations::delete((int)$loc['id']);
\Cjenik\Support::audit('cli', 'location.delete', $loc['grp'] . '/' . $loc['betnr']);
\Cjenik\Pages::renderAll();
echo "Obrisano: {$loc['grp']}/{$loc['betnr']}, datoteka: {$r['files']}" . ($r['concession_removed'] ? ", koncesija je ostala bez lokacija pa je obrisana" : '') . ".\n";
