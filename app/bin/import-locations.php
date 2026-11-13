<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

// php app/bin/import-locations.php seed/locations.csv
$file = (string)($argv[1] ?? '');
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Upotreba: php import-locations.php <datoteka.csv>\n");
    exit(1);
}
$r = \Cjenik\Locations::importCsv($file);
\Cjenik\Pages::renderAll();
echo "Lokacije: dodano {$r['created']}, azurirano {$r['updated']}, preskoceno {$r['skipped']}. Javne stranice generirane.\n";
