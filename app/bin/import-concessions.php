<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

// php app/bin/import-concessions.php seed/concessions.csv   (zaglavlje: grp;naziv[;slug][;web_url])
$file = (string)($argv[1] ?? '');
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Upotreba: php import-concessions.php <datoteka.csv>\n");
    exit(1);
}
$r = \Cjenik\Locations::importConcessionsCsv($file);
\Cjenik\Pages::renderAll();
echo "Koncesije: dodano {$r['created']}, azurirano {$r['updated']}. Javne stranice generirane.\n";
