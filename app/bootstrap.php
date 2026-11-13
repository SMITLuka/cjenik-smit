<?php
declare(strict_types=1);

define('APP_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Cjenik\\')) {
        $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Greske nikad ne idu u odgovor; idu u log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$configPath = getenv('CJENIK_CONFIG') ?: APP_ROOT . '/config.php';
if (!defined('CJENIK_TESTING')) {
    if (!is_file($configPath)) {
        http_response_code(500);
        error_log('cjenik: config.php ne postoji');
        exit("Konfiguracija nedostaje.\n");
    }
    \Cjenik\Config::load(require $configPath);
    $storage = \Cjenik\Config::get('storage');
    if (is_string($storage)) {
        if (!is_dir($storage . '/logs')) {
            @mkdir($storage . '/logs', 0750, true);
        }
        ini_set('error_log', $storage . '/logs/php-error.log');
    }
    date_default_timezone_set((string)\Cjenik\Config::get('timezone', 'Europe/Zagreb'));
}
