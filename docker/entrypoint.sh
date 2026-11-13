#!/bin/sh
# Pokretanje kontejnera: priprema web direktorij, migrira bazu, regenerira javne stranice, pokrece cron petlju i Apache.
set -eu

WEB=/var/www/html
STORAGE=/var/www/storage
SRC=/opt/cjenik/public_html
RUN_AS="su -s /bin/sh www-data -c"

mkdir -p "$WEB" "$WEB/assets" "$STORAGE/logs"

# Kod web dijela uvijek dolazi iz slike. Objavljeni sadrzaj (/<koncesija>/..., CSV) ostaje u volumenu.
cp -f "$SRC/index.php" "$SRC/.htaccess" "$WEB/"
cp -f "$SRC/assets/"* "$WEB/assets/"
chown -R www-data:www-data "$WEB" "$STORAGE"

# Migracija baze (idempotentna). Ceka bazu do ~60 s (docker compose pokrece ih istodobno).
i=0
until $RUN_AS "php /var/www/app/bin/migrate.php" >/tmp/migrate.log 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then
        echo "Migracija baze nije uspjela:" >&2
        cat /tmp/migrate.log >&2
        exit 1
    fi
    sleep 2
done
cat /tmp/migrate.log

# Javne stranice iz baze (prazno ako jos nema lokacija).
$RUN_AS "php /var/www/app/bin/rebuild.php" || echo "rebuild nije uspio (nastavljam)" >&2

# Cron svakih 5 minuta: retencija 30 dana, watchdog, prijenos jucerasnjeg cjenika.
(
    while true; do
        sleep 300
        $RUN_AS "php /var/www/app/bin/cron.php" || true
    done
) &

exec apache2-foreground
