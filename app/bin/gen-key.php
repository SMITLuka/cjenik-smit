<?php
declare(strict_types=1);

// Ispisuje novi app_key za config.php (ne treba config).
echo 'base64:' . base64_encode(random_bytes(32)) . "\n";
