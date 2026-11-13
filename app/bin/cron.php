<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

// Pokretati svakih 5 minuta (cron):  */5 * * * * php /path/app/bin/cron.php
$lock = fopen((string)\Cjenik\Config::get('storage') . '/cron.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // vec radi
}
$s = \Cjenik\Maintenance::run();
if ($s['retention_deleted'] || $s['carried'] || $s['no_previous']) {
    echo json_encode($s, JSON_UNESCAPED_UNICODE) . "\n";
}
