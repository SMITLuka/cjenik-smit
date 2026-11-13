<?php
declare(strict_types=1);

/**
 * Samo za LOKALNI test s ugrađenim PHP serverom (Apache na hostingu radi ovo preko .htaccess):
 *   php -S 127.0.0.1:8099 -t public_html dev/router.php
 * - /api/* i /admin/* -> index.php
 * - mape -> index.html, postojece datoteke se poslužuju kao staticke
 * - ostalo -> index.php (404)
 */
$root = (string)$_SERVER['DOCUMENT_ROOT'];
$path = rawurldecode((string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (preg_match('#^/(api|admin)(/|$)#', $path) === 1) {
    require $root . '/index.php';
    return true;
}

$file = $root . $path;
if (is_dir($file)) {
    $file = rtrim($file, '/\\') . '/index.html';
}
$real = realpath($file);
if ($real !== false && is_file($real) && str_starts_with($real, realpath($root) . DIRECTORY_SEPARATOR) && !str_ends_with($real, '.php')) {
    $types = ['html' => 'text/html; charset=utf-8', 'csv' => 'text/csv; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'txt' => 'text/plain; charset=utf-8', 'xml' => 'application/xml; charset=utf-8'];
    header('Content-Type: ' . ($types[strtolower(pathinfo($real, PATHINFO_EXTENSION))] ?? 'application/octet-stream'));
    readfile($real);
    return true;
}

require $root . '/index.php';
return true;
