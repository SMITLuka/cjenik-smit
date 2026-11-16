<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

\Cjenik\Schema::migrate();
echo "Shema je azurirana.\n";
// jednokratno: stari odvojeni cjenici dijelovi/vozila zamijenjeni su jednim cjenikom 'proizvodi'
$p = \Cjenik\Locations::purgeLegacyFeeds();
if ($p['done']) {
    echo "Obrisani stari cjenici dijelovi/vozila: {$p['uploads']} zapisa, {$p['files']} datoteka.\n";
}
