-- Baza i glavni korisnik aplikacije cjenik-smit. Izvrsava administrator baze (root), jednom.
-- LOZINKU generirati (npr. openssl rand -base64 24) i upisati samo u Coolify env DB_PASS; NE commitati.
-- Korisnik 'cjenik_app' se koristi iskljucivo iz aplikacije; nema pristup drugim bazama.

CREATE DATABASE IF NOT EXISTS cjenik CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- '%' zamijeniti adresom/mrezom kontejnera ili poslužitelja aplikacije ako je poznata (npr. '10.0.%' ili 'app-host').
CREATE USER IF NOT EXISTS 'cjenik_app'@'%' IDENTIFIED BY 'ZAMIJENI_GENERIRANOM_LOZINKOM';

-- Varijanta A (zadano): aplikacija pri pokretanju sama kreira/azurira tablice (migrate.php), pa treba i CREATE/ALTER/INDEX.
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON cjenik.* TO 'cjenik_app'@'%';

-- Varijanta B (strozi nadzor): tablice kreira administrator iz 01-schema.sql, aplikaciji samo podaci.
-- Tada MIJESATI: umjesto gornjeg GRANT-a izvrsiti donji, a kontejner pokrenuti sa SKIP_MIGRATE=1.
-- GRANT SELECT, INSERT, UPDATE, DELETE ON cjenik.* TO 'cjenik_app'@'%';

FLUSH PRIVILEGES;
