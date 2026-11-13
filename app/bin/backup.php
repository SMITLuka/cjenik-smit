<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

use Cjenik\Config;
use Cjenik\Db;

// Dnevni backup baze (JSON, gzip). Arhiva CSV-a je u public_html/<grp>/<betnr>/..., kopiraj je rsync-om
// (vidi README, "Backup"). Cuva zadnjih 14 kopija. NE sadrzi app_key — njega cuvaj odvojeno!
$dir = (string)Config::get('storage') . '/backups';
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    fwrite(STDERR, "Ne mogu napraviti $dir\n");
    exit(1);
}
$dump = ['created' => gmdate('c'), 'tables' => []];
foreach (['concessions', 'locations', 'uploads', 'api_keys', 'admins', 'audit_log', 'state'] as $t) {
    $dump['tables'][$t] = Db::all("SELECT * FROM $t");
}
$file = $dir . '/db-' . gmdate('Ymd-His') . '.json.gz';
file_put_contents($file, gzencode(json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}', 9));
@chmod($file, 0600);
$all = glob($dir . '/db-*.json.gz') ?: [];
sort($all);
foreach (array_slice($all, 0, max(0, count($all) - 14)) as $old) {
    @unlink($old);
}
echo "Backup: $file\n";
