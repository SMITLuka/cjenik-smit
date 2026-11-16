<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

use Cjenik\Locations;
use Cjenik\Pages;
use Cjenik\Support;

// Grupno ukljucuje/iskljucuje cjenik (dijelovi|vozila|radovi) za lokacije.
//   php app/bin/set-feeds.php <cjenik> <on|off> <lokacija>... [--dry-run]
// <lokacija> je grp/broj (npr. ka4/101), samo grp (sve lokacije koncesije), 'all' ili @datoteka
// (jedna lokacija po retku; prazni retci i retci s # se preskacu), npr. @/var/www/seed/bez-vozila.txt
$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => $a !== '--dry-run'));
$dry = in_array('--dry-run', $argv, true);
if (count($args) < 3) {
    fwrite(STDERR, "Upotreba: php set-feeds.php <dijelovi|vozila|radovi> <on|off> <ka4/101|ka4|all|@datoteka>... [--dry-run]\n");
    exit(1);
}
$feed = array_shift($args);
$state = array_shift($args);
if (!\Cjenik\CsvSpec::isFeed($feed) || !in_array($state, ['on', 'off'], true)) {
    fwrite(STDERR, "Neispravan cjenik ili stanje (on|off).\n");
    exit(1);
}
$on = $state === 'on';

// razrijesi argumente u popis lokacija
$wanted = [];
foreach ($args as $a) {
    if (str_starts_with($a, '@')) {
        $file = substr($a, 1);
        if (!is_file($file)) {
            fwrite(STDERR, "Datoteka ne postoji: $file\n");
            exit(1);
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $wanted[] = strtolower($line);
            }
        }
    } else {
        $wanted[] = strtolower($a);
    }
}

$all = Locations::all(false);
$targets = [];
foreach ($wanted as $w) {
    $found = false;
    foreach ($all as $l) {
        $key = $l['grp'] . '/' . (int)$l['betnr'];
        if ($w === 'all' || $w === $key || $w === $l['grp']) {
            $targets[(int)$l['id']] = $key;
            $found = true;
        }
    }
    if (!$found) {
        fwrite(STDERR, "Upozorenje: lokacija '$w' ne postoji, preskacem.\n");
    }
}
if ($targets === []) {
    fwrite(STDERR, "Nema lokacija za obradu.\n");
    exit(1);
}

$changed = 0;
$same = 0;
foreach ($targets as $id => $key) {
    if ($dry) {
        echo "[dry-run] $key: $feed -> $state\n";
        continue;
    }
    if (Locations::setFeed($id, $feed, $on)) {
        $changed++;
        Support::audit('cli', 'location.feed', "$key: $feed " . $state);
        echo "$key: $feed $state\n";
    } else {
        $same++;
    }
}
if (!$dry) {
    Pages::renderAll();
    echo "Promijenjeno: $changed, vec u tom stanju: $same.\n";
}
