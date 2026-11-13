<?php
declare(strict_types=1);

/** Zajednicki bootstrap za CLI skripte: samo iz komandne linije, nikad preko weba. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/bootstrap.php';
