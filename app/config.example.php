<?php
/**
 * Kopiraj u app/config.php i prilagodi. config.php NIKAD ne ide u javni dio (public_html)
 * niti u verzioniranje — sadrzi tajne.
 */
return [
    // 32 nasumicna bajta, base64. Generiraj: php app/bin/gen-key.php
    // Sluzi za sifriranje HMAC tajni i TOTP tajni u bazi. Ako se izgubi, svi kljucevi i 2FA se moraju ponovno izdati.
    'app_key' => 'base64:REPLACE_ME',

    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=CHANGE_ME;charset=utf8mb4',
        'user' => 'CHANGE_ME',
        'pass' => 'CHANGE_ME',
    ],

    // Direktorij u koji se objavljuju CSV/HTML datoteke (document root domene, npr. ~/public_html).
    'webroot' => dirname(__DIR__) . '/public_html',
    // Izvan document roota: logovi, backupi.
    'storage' => dirname(__DIR__) . '/storage',

    'base_url' => 'https://cjenik.sm-it.hr',
    'timezone' => 'Europe/Zagreb',

    // Operator stranice (prikazuje se u podnozju javnih stranica).
    'operator' => ['name' => 'SM-IT', 'contact' => ''],

    // IP allowlist (pojedinacne adrese ili CIDR). PRAZNO = bez provjere (samo za razvoj!).
    // ingest: izlazna javna IP adresa vase VPN mreze. admin: vase IP adrese.
    'ingest_allowed_ips' => [],
    'admin_allowed_ips'  => [],

    // Ako je ispred PHP-a proxy (npr. Hetznerov Varnish), upisi zaglavlje iz kojeg se cita stvarna IP
    // klijenta, npr. 'HTTP_X_FORWARDED_FOR'. Provjeri na stvarnom hostingu! NULL = koristi REMOTE_ADDR.
    'client_ip_header' => null,
    // Broj pouzdanih proxyja ispred aplikacije. Cita se adresa s kraja X-Forwarded-For (koju je dodao proxy), nikad prva.
    'client_ip_hops' => 1,

    // false samo za lokalni razvoj bez HTTPS-a.
    'require_https' => true,

    'alert_email' => '',   // primatelj upozorenja (watchdog, greske)
    'mail_from'   => '',   // npr. cjenik@cjenik.sm-it.hr

    'max_body_bytes' => 100 * 1024 * 1024, // nakon dekompresije ne provjerava se, vidi max_rows
    'max_rows'       => 2000000,
    'timestamp_window_seconds' => 300,
    'retention_days' => 30,

    // Watchdog (cron/cron.php, pokretati svakih 5 min).
    'watchdog' => [
        'alert_at'     => '07:40', // upozorenje ako lokacija nema danasnji upload
        'carryover_at' => '07:55', // prenesi jucerasnji cjenik kao danasnji
        'weekdays_only' => false,
    ],
];
