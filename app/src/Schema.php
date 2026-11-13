<?php
declare(strict_types=1);

namespace Cjenik;

/** Shema radi i na MariaDB (produkcija) i na SQLite (testovi). Datumi su VARCHAR 'Y-m-d' / UTC 'Y-m-d H:i:s'. */
final class Schema
{
    public static function migrate(): void
    {
        $pk = Db::isSqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
        $tail = Db::isSqlite() ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $tables = [
            "CREATE TABLE IF NOT EXISTS concessions (
                id $pk,
                grp VARCHAR(8) NOT NULL,
                naziv VARCHAR(200) NOT NULL,
                web_url VARCHAR(300) NULL,
                slug VARCHAR(60) NULL,
                created_at VARCHAR(19) NOT NULL,
                UNIQUE (grp),
                UNIQUE (slug)
            )$tail",
            "CREATE TABLE IF NOT EXISTS locations (
                id $pk,
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
            )$tail",
            "CREATE TABLE IF NOT EXISTS uploads (
                id $pk,
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
            )$tail",
            "CREATE TABLE IF NOT EXISTS api_keys (
                id $pk,
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
            )$tail",
            "CREATE TABLE IF NOT EXISTS nonces (
                key_id VARCHAR(32) NOT NULL,
                nonce VARCHAR(64) NOT NULL,
                ts INT NOT NULL,
                UNIQUE (key_id, nonce)
            )$tail",
            "CREATE TABLE IF NOT EXISTS admins (
                id $pk,
                username VARCHAR(60) NOT NULL,
                pass_hash VARCHAR(255) NOT NULL,
                totp_secret_enc TEXT NULL,
                totp_confirmed TINYINT NOT NULL DEFAULT 0,
                totp_last_step INT NOT NULL DEFAULT 0,
                disabled TINYINT NOT NULL DEFAULT 0,
                created_at VARCHAR(19) NOT NULL,
                UNIQUE (username)
            )$tail",
            "CREATE TABLE IF NOT EXISTS auth_failures (
                id $pk,
                scope VARCHAR(10) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                ts INT NOT NULL
            )$tail",
            "CREATE TABLE IF NOT EXISTS audit_log (
                id $pk,
                ts VARCHAR(19) NOT NULL,
                actor VARCHAR(80) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                action VARCHAR(60) NOT NULL,
                detail TEXT NULL
            )$tail",
            "CREATE TABLE IF NOT EXISTS state (
                k VARCHAR(80) NOT NULL PRIMARY KEY,
                v TEXT NULL
            )$tail",
        ];
        foreach ($tables as $sql) {
            Db::pdo()->exec($sql);
        }
        // Migracija starijih baza: tri odvojena feeda (dijelovi, vozila, radovi) umjesto proizvodi/usluge.
        // Stari stupci feed_proizvodi/feed_usluge ostaju u tablici, ali se vise ne koriste.
        if (!self::hasColumn('locations', 'feed_dijelovi')) {
            foreach (['feed_dijelovi', 'feed_vozila', 'feed_radovi'] as $col) {
                Db::pdo()->exec("ALTER TABLE locations ADD COLUMN $col TINYINT NOT NULL DEFAULT 1");
            }
            Db::pdo()->exec('UPDATE locations SET feed_dijelovi = feed_proizvodi, feed_vozila = feed_proizvodi, feed_radovi = feed_usluge');
        }
        // Migracija: naziv datoteke po objavi (seq + file_name u uploads) i oblik prodajnog mjesta.
        if (!self::hasColumn('uploads', 'file_name')) {
            Db::pdo()->exec('ALTER TABLE uploads ADD COLUMN seq INT NOT NULL DEFAULT 0');
            Db::pdo()->exec('ALTER TABLE uploads ADD COLUMN file_name VARCHAR(200) NULL');
        }
        if (!self::hasColumn('locations', 'oblik_mjesta')) {
            Db::pdo()->exec('ALTER TABLE locations ADD COLUMN oblik_mjesta VARCHAR(50) NULL');
        }
        // Migracija starijih baza: javna oznaka koncesije (slug).
        if (!self::hasColumn('concessions', 'slug')) {
            Db::pdo()->exec('ALTER TABLE concessions ADD COLUMN slug VARCHAR(60) NULL');
            Db::pdo()->exec('CREATE UNIQUE INDEX concessions_slug_uq ON concessions (slug)');
        }
    }

    private static function hasColumn(string $table, string $column): bool
    {
        if (Db::isSqlite()) {
            foreach (Db::all("PRAGMA table_info($table)") as $c) {
                if ($c['name'] === $column) {
                    return true;
                }
            }
            return false;
        }
        return Db::one("SHOW COLUMNS FROM $table LIKE '$column'") !== null;
    }
}
