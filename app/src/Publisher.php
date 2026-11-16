<?php
declare(strict_types=1);

namespace Cjenik;

use DateTimeImmutable;
use RuntimeException;

/** Objava CSV-a na disk (staticke datoteke), evidencija u bazi, retencija 30 dana. */
final class Publisher
{
    public static function webroot(): string
    {
        $w = (string)Config::get('webroot', '');
        if ($w === '') {
            throw new RuntimeException('webroot nije konfiguriran');
        }
        return rtrim($w, '/\\');
    }

    /** @param array<string,mixed> $loc */
    public static function feedDir(array $loc, string $feed): string
    {
        $path = Locations::pathSlug((string)$loc['grp']);
        if (!Support::validGrp((string)$loc['grp']) || !Locations::validPath($path) || !CsvSpec::isFeed($feed)) {
            throw new RuntimeException('Neispravna putanja lokacije/feeda');
        }
        return self::webroot() . '/' . $path . '/' . (int)$loc['betnr'] . '/' . $feed;
    }

    public static function writeAtomic(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Ne mogu napraviti direktorij $dir");
        }
        $tmp = $path . '.tmp' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Ne mogu pisati $tmp");
        }
        self::replace($tmp, $path);
    }

    /**
     * Atomska zamjena (rename). Na Linuxu (produkcija) uvijek uspije; na Windowsu rename povremeno
     * padne ako je odrediste kratko otvoreno (antivirus/indexer), pa se kratko ponovi.
     */
    private static function replace(string $tmp, string $dst): void
    {
        @chmod($tmp, 0644);
        for ($i = 0; $i < 8; $i++) {
            if (@rename($tmp, $dst)) {
                return;
            }
            usleep(25000 * ($i + 1));
        }
        if (@copy($tmp, $dst)) {
            @unlink($tmp);
            return;
        }
        @unlink($tmp);
        throw new RuntimeException("Ne mogu zamijeniti $dst");
    }

    /** Kopira datoteku atomski i uklanja UTF-8 BOM ako ga ima. */
    private static function copyClean(string $src, string $dst): void
    {
        $dir = dirname($dst);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Ne mogu napraviti direktorij $dir");
        }
        $in = fopen($src, 'rb');
        if ($in === false) {
            throw new RuntimeException('Ne mogu citati izvor');
        }
        if (fread($in, 3) !== "\xEF\xBB\xBF") {
            rewind($in);
        }
        $tmp = $dst . '.tmp' . bin2hex(random_bytes(4));
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Ne mogu pisati odrediste');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        self::replace($tmp, $dst);
    }

    /**
     * Objavljuje vec VALIDIRANU datoteku.
     * @param array<string,mixed> $loc
     * @return array{path:string, bytes:int, sha256:string}
     */
    public static function publish(array $loc, string $feed, string $date, string $srcFile, int $rows, string $source, ?string $keyId, bool $render = true): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new RuntimeException('Neispravan datum');
        }
        $dir = self::feedDir($loc, $feed);
        $existing = Db::one('SELECT id, file_name FROM uploads WHERE location_id = ? AND feed = ? AND price_date = ?', [$loc['id'], $feed, $date]);

        // Svaka objava je posebna datoteka s nazivom prema Pravilniku (cl. 6(2)): objekt, oblik, adresa, oznaka,
        // feed, broj pohrane (redni broj objave) i vremenska oznaka (datum i vrijeme slanja).
        $now = Clock::utcStamp();
        $seq = self::nextSeq((int)$loc['id'], $feed);
        $name = Pages::downloadName($loc, $feed, $seq, $now);
        $target = $dir . '/' . $name;
        self::copyClean($srcFile, $target);
        if ($date === Clock::today()) {
            self::copyClean($target, $dir . '/latest.csv');
            // stalna adresa latest.csv se preuzima pod pravim nazivom datoteke (ne pod 'latest.csv')
            Support::setState('latest:' . (int)$loc['id'] . ':' . $feed, $name);
            self::writeLatestHeaders($loc, $feed, $name);
        }
        // ponovni upload istog dana zamjenjuje prethodnu datoteku tog dana
        if ($existing !== null) {
            $old = self::fileNameOf($existing + ['price_date' => $date]);
            if ($old !== $name && is_file($dir . '/' . $old)) {
                @unlink($dir . '/' . $old);
            }
        }
        $bytes = (int)filesize($target);
        $sha = hash_file('sha256', $target);
        if ($existing !== null) {
            Db::run(
                'UPDATE uploads SET rows_count=?, bytes=?, sha256=?, source=?, key_id=?, seq=?, file_name=?, created_at=? WHERE id=?',
                [$rows, $bytes, $sha, $source, $keyId, $seq, $name, $now, $existing['id']]
            );
        } else {
            Db::run(
                'INSERT INTO uploads (location_id, feed, price_date, rows_count, bytes, sha256, source, key_id, seq, file_name, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$loc['id'], $feed, $date, $rows, $bytes, $sha, $source, $keyId, $seq, $name, $now]
            );
        }
        $dated = $target;
        self::retention();
        if ($render) {
            Pages::renderAll();
        }
        return ['path' => $dated, 'bytes' => $bytes, 'sha256' => $sha];
    }

    /**
     * Uz latest.csv upisuje .htaccess koji postavlja Content-Disposition s pravim nazivom datoteke, pa se stalna adresa
     * (npr. .../proizvodi/latest.csv) spremi kao npr. rb-auto_autosalon_..._000007_20261117070000.csv. Zaglavlje radi
     * i za automatske alate (curl -OJ) i preglednike; sadrzaj je i dalje izravno posluzen (200, bez preusmjeravanja).
     * @param array<string,mixed> $loc
     */
    public static function writeLatestHeaders(array $loc, string $feed, string $fileName): void
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,200}$/', $fileName) !== 1) {
            return; // nikad nesiguran znak u zaglavlju
        }
        $dir = self::feedDir($loc, $feed);
        if (!is_dir($dir)) {
            return;
        }
        self::writeAtomic($dir . '/.htaccess',
            "# Generirano: stalna adresa latest.csv preuzima se pod pravim nazivom datoteke.
"
            . "<IfModule mod_headers.c>
<Files \"latest.csv\">
    Header set Content-Disposition \"attachment; filename=\\\"$fileName\\\"\"
</Files>
</IfModule>
");
    }

    /** Brise sve starije od retention_days (30 datoteka po lokaciji i feedu). @return int obrisano */
    public static function retention(): int
    {
        $days = max(1, (int)Config::get('retention_days', 30));
        $cutoff = (new DateTimeImmutable(Clock::today()))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $rows = Db::all(
            'SELECT u.id, u.feed, u.price_date, u.file_name, l.id AS lid, l.grp, l.betnr FROM uploads u JOIN locations l ON l.id = u.location_id WHERE u.price_date < ?',
            [$cutoff]
        );
        $n = 0;
        foreach ($rows as $r) {
            $file = self::feedDir(['id' => $r['lid'], 'grp' => $r['grp'], 'betnr' => $r['betnr']], (string)$r['feed']) . '/' . self::fileNameOf($r);
            if (is_file($file)) {
                @unlink($file);
            }
            Db::run('DELETE FROM uploads WHERE id = ?', [$r['id']]);
            $n++;
        }
        return $n;
    }

    /** Naziv objavljene datoteke (stari zapisi bez file_name imaju datumski naziv). @param array<string,mixed> $row */
    public static function fileNameOf(array $row): string
    {
        $n = (string)($row['file_name'] ?? '');
        $n = basename($n); // nikad putanja
        return $n !== '' ? $n : (string)$row['price_date'] . '.csv';
    }

    /** Redni broj objave ("broj pohrane") po lokaciji i cjeniku; ne resetira se brisanjem starih objava. */
    private static function nextSeq(int $locationId, string $feed): int
    {
        $key = 'seq:' . $locationId . ':' . $feed;
        $n = (int)(Support::getState($key) ?? 0) + 1;
        Support::setState($key, (string)$n);
        return $n;
    }

    /** Najnoviji upload prije zadanog datuma (za prijenos jucerasnjeg). @return array<string,mixed>|null */
    public static function previousUpload(int $locationId, string $feed, string $beforeDate): ?array
    {
        return Db::one(
            'SELECT * FROM uploads WHERE location_id = ? AND feed = ? AND price_date < ? ORDER BY price_date DESC LIMIT 1',
            [$locationId, $feed, $beforeDate]
        );
    }
}
