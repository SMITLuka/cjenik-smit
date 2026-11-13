<?php
/**
 * Konfiguracija iz varijabli okruzenja (za Docker). U ovoj datoteci nema tajni: sve dolazi iz okruzenja.
 * Dockerfile je kopira u app/config.php. Obavezno: APP_KEY, DB_HOST (ili DB_DSN), DB_NAME, DB_USER, DB_PASS.
 * Popis varijabli: docker/README ili README.md (odjeljak Docker).
 */

$env = static function (string $k, ?string $default = null): ?string {
    $v = getenv($k);
    return ($v === false || $v === '') ? $default : $v;
};
$list = static function (?string $s): array {
    if ($s === null) {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $s)), static fn($x) => $x !== ''));
};
$bool = static function (?string $s, bool $default): bool {
    return $s === null ? $default : in_array(strtolower($s), ['1', 'true', 'yes', 'on'], true);
};

$appKey = $env('APP_KEY');
if ($appKey === null) {
    throw new RuntimeException('APP_KEY nije postavljen (php app/bin/gen-key.php).');
}

$dsn = $env('DB_DSN') ?? sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $env('DB_HOST', 'db'),
    $env('DB_PORT', '3306'),
    $env('DB_NAME', 'cjenik')
);

return [
    'app_key' => $appKey,

    'db' => [
        'dsn'  => $dsn,
        'user' => $env('DB_USER', 'cjenik'),
        'pass' => $env('DB_PASS', ''),
    ],

    // Document root web servera (u slici /var/www/html; za trajnost objavljenih cjenika montirati volumen).
    'webroot' => '/var/www/html',
    // Izvan document roota: logovi, backupi (montirati volumen).
    'storage' => '/var/www/storage',

    'base_url' => $env('BASE_URL', 'https://cjenik.sm-it.hr'),
    'timezone' => $env('TIMEZONE', 'Europe/Zagreb'),

    'operator' => ['name' => $env('OPERATOR_NAME', 'SM-IT'), 'contact' => $env('OPERATOR_CONTACT', '')],

    // IP allowlist (zarez-odvojene adrese ili CIDR). PRAZNO = bez provjere (samo za razvoj!).
    'ingest_allowed_ips' => $list($env('INGEST_ALLOWED_IPS')),
    'admin_allowed_ips'  => $list($env('ADMIN_ALLOWED_IPS')),

    // Iza reverse proxyja (TLS i stvarna IP klijenta): npr. HTTP_X_FORWARDED_FOR. Postavljati SAMO ako je kontejner
    // dostupan iskljucivo preko proxyja koji to zaglavlje sam postavlja (inace ga klijent moze krivotvoriti).
    'client_ip_header' => $env('CLIENT_IP_HEADER'),

    // false samo za lokalni razvoj bez HTTPS-a.
    'require_https' => $bool($env('REQUIRE_HTTPS'), true),

    'alert_email' => $env('ALERT_EMAIL', ''),
    'mail_from'   => $env('MAIL_FROM', ''),

    'max_body_bytes' => 100 * 1024 * 1024,
    'max_rows'       => 2000000,
    'timestamp_window_seconds' => 300,
    'retention_days' => (int)$env('RETENTION_DAYS', '30'),

    'watchdog' => [
        'alert_at'      => $env('WATCHDOG_ALERT_AT', '07:40'),
        'carryover_at'  => $env('WATCHDOG_CARRYOVER_AT', '07:55'),
        'weekdays_only' => $bool($env('WATCHDOG_WEEKDAYS_ONLY'), false),
    ],
];
