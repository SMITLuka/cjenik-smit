<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

use Cjenik\Locations;
use Cjenik\Pages;
use Cjenik\Support;

// Grupno ukljucuje/iskljucuje izvor cjenika (dijelovi|vozila|radovi) za lokacije. Vozila i dijelovi objavljuju se
// zajedno u cjeniku 'proizvodi'; 'proizvodi' kao argument znaci oba izvora (dijelovi i vozila).
//   php app/bin/set-feeds.php <izvor> <on|off> <lokacija>... [--dry-run]
// <lokacija> je grp/broj (npr. ka4/101), samo grp (sve lokacije koncesije), 'all' ili @datoteka
// (jedna lokacija po retku; prazni retci i retci s # se preskacu), npr. @/var/www/seed/bez-vozila.txt
$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => $a !== '--dry-run'));
$dry = in_array('--dry-run', $argv, true);
if (count($args) < 3) {
    fwrite(STDERR, "Upotreba: php set-feeds.php <dijelovi|vozila|radovi|proizvodi> <on|off> <ka4/101|ka4|all|@datoteka>... [--dry-run]\n");
    exit(1);
}
$feed = array_shift($args);
$state = array_shift($args);
$sources = $feed === 'proizvodi' ? ['dijelovi', 'vozila'] : [$feed];
foreach ($sources as $s) {
    if (!isset(Locations::SOURCES[$s])) {
        fwrite(STDERR, "Neispravan izvor (dijelovi, vozila, radovi ili proizvodi).\n");
        exit(1);
    }
}
if (!in_array($state, ['on', 'off'], true)) {
    fwrite(STDERR, "Neispravno stanje (on|off).\n");
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
    foreach ($sources as $src) {
        if ($dry) {
            echo "[dry-run] $key: $src -> $state\n";
            continue;
        }
        if (Locations::setFeed($id, $src, $on)) {
            $changed++;
            Support::audit('cli', 'location.feed', "$key: $src " . $state);
            echo "$key: $src $state\n";
        } else {
            $same++;
        }
    }
}
if (!$dry) {
    Pages::renderAll();
    echo "Promijenjeno: $changed, vec u tom stanju: $same.\n";
}
