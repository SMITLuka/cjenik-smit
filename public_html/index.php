<?php
declare(strict_types=1);

/**
 * Front controller: samo /api/* i /admin/*. Sve javno (HTML/CSV) su staticke datoteke koje
 * web server poslužuje izravno (bez PHP-a). Ostalo -> 404.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

try {
    if (str_starts_with($path, '/api/')) {
        \Cjenik\Api::handle();
        return;
    }
    if ($path === '/admin' || str_starts_with($path, '/admin/')) {
        \Cjenik\Admin::handle($path);
        return;
    }
} catch (\Throwable $t) {
    error_log('cjenik: ' . $t::class . ': ' . $t->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Interna greška.\n";
    return;
}

http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><meta charset="utf-8"><title>404</title><p>Stranica ne postoji. <a href="/">Početna</a></p>';
