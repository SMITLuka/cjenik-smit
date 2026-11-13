<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

\Cjenik\Schema::migrate();
echo "Shema je azurirana.\n";
