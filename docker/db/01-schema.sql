-- Shema baze cjenik-smit za MariaDB 10.5+/11 (InnoDB, utf8mb4).
-- GENERIRANO iz app/src/Schema.php; aplikacija ovo sama izvrsava pri pokretanju (migrate.php, idempotentno),
-- pa je ova datoteka potrebna samo ako se tablice zele kreirati rucno (tada pokrenuti kontejner sa SKIP_MIGRATE=1).
-- Napomena: datumi su VARCHAR(19) UTC 'Y-m-d H:i:s' (price_date CHAR(10) 'Y-m-d'); to je namjerno (isti kod radi
-- i na SQLite-u u testovima). Veze izmedju tablica osigurava aplikacija (brisanje lokacije u jednoj transakciji).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS concessions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    grp VARCHAR(8) NOT NULL,
    naziv VARCHAR(200) NOT NULL,
    web_url VARCHAR(300) NULL,
    slug VARCHAR(60) NULL,
    created_at VARCHAR(19) NOT NULL,
    UNIQUE (grp),
    UNIQUE (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    grp VARCHAR(8) NOT NULL,
    betnr INT NOT NULL,
    naziv VARCHAR(200) NOT NULL,
    pravni_naziv VARCHAR(200) NULL,
    adresa VARCHAR(300) NULL,
    oznaka_objekta VARCHAR(50) NULL,
    broj_pohrane VARCHAR(50) NULL,
    oblik_mjesta VARCHAR(50) NULL,
    feed_proizvodi TINYINT NOT NULL DEFAULT 1,
    feed_usluge TINYINT NOT NULL DEFAULT 1,
    feed_dijelovi TINYINT NOT NULL DEFAULT 1,
    feed_vozila TINYINT NOT NULL DEFAULT 1,
    feed_radovi TINYINT NOT NULL DEFAULT 1,
    active TINYINT NOT NULL DEFAULT 1,
    created_at VARCHAR(19) NOT NULL,
    UNIQUE (grp, betnr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uploads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    location_id INT NOT NULL,
    feed VARCHAR(12) NOT NULL,
    price_date CHAR(10) NOT NULL,
    rows_count INT NOT NULL,
    bytes INT NOT NULL,
    sha256 CHAR(64) NOT NULL,
    source VARCHAR(12) NOT NULL,
    key_id VARCHAR(32) NULL,
    seq INT NOT NULL DEFAULT 0,
    file_name VARCHAR(200) NULL,
    created_at VARCHAR(19) NOT NULL,
    UNIQUE (location_id, feed, price_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_keys (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    key_id VARCHAR(32) NOT NULL,
    secret_enc TEXT NOT NULL,
    scope VARCHAR(10) NOT NULL,
    grp VARCHAR(8) NULL,
    location_id INT NULL,
    label VARCHAR(100) NOT NULL,
    created_at VARCHAR(19) NOT NULL,
    revoked_at VARCHAR(19) NULL,
    last_used_at VARCHAR(19) NULL,
    UNIQUE (key_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS nonces (
    key_id VARCHAR(32) NOT NULL,
    nonce VARCHAR(64) NOT NULL,
    ts INT NOT NULL,
    UNIQUE (key_id, nonce)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(60) NOT NULL,
    pass_hash VARCHAR(255) NOT NULL,
    totp_secret_enc TEXT NULL,
    totp_confirmed TINYINT NOT NULL DEFAULT 0,
    totp_last_step INT NOT NULL DEFAULT 0,
    disabled TINYINT NOT NULL DEFAULT 0,
    created_at VARCHAR(19) NOT NULL,
    UNIQUE (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_failures (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(10) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    ts INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ts VARCHAR(19) NOT NULL,
    actor VARCHAR(80) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    action VARCHAR(60) NOT NULL,
    detail TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS state (
    k VARCHAR(80) NOT NULL PRIMARY KEY,
    v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Povijesne migracije aplikacije (stari stupci feed_proizvodi/feed_usluge ostaju u tablici locations, ali se ne koriste).
