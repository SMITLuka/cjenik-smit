<?php
declare(strict_types=1);

namespace Cjenik;

use InvalidArgumentException;

final class Locations
{
    /** @return list<array<string,mixed>> */
    public static function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM locations' . ($onlyActive ? ' WHERE active = 1' : '') . ' ORDER BY grp, betnr';
        return Db::all($sql);
    }

    /** @return array<string,mixed>|null */
    public static function find(string $grp, int $betnr): ?array
    {
        return Db::one('SELECT * FROM locations WHERE grp = ? AND betnr = ?', [$grp, $betnr]);
    }

    /** @return array<string,mixed>|null */
    public static function byId(int $id): ?array
    {
        return Db::one('SELECT * FROM locations WHERE id = ?', [$id]);
    }

    /** @return list<string> aktivni feedovi lokacije */
    public static function feeds(array $loc): array
    {
        $f = [];
        foreach (array_keys(CsvSpec::FEEDS) as $feed) {
            if ((int)($loc['feed_' . $feed] ?? 0) === 1) {
                $f[] = $feed;
            }
        }
        return $f;
    }

    /** @return list<array<string,mixed>> */
    public static function concessions(): array
    {
        return Db::all('SELECT * FROM concessions ORDER BY grp');
    }

    /** Javna putanja koncesije u URL-u: slug ako je postavljen, inace oznaka (GRP). */
    public static function pathSlug(string $grp): string
    {
        $s = Db::val('SELECT slug FROM concessions WHERE grp = ?', [$grp]);
        return ($s !== null && $s !== '') ? (string)$s : $grp;
    }

    /** Je li putanja sigurna za pisanje u webroot (slug ili GRP). */
    public static function validPath(string $p): bool
    {
        return Support::validSlug($p) || Support::validGrp($p);
    }

    /**
     * Postavlja javnu oznaku koncesije (slug) i premjesta vec objavljenu mapu. Prazan slug = vrati na GRP.
     * Stari URL prestaje postojati (404).
     */
    public static function setSlug(string $grp, ?string $slug): void
    {
        $slug = $slug === null ? '' : strtolower(trim($slug));
        if (Db::val('SELECT COUNT(*) FROM concessions WHERE grp = ?', [$grp]) == 0) {
            throw new InvalidArgumentException('Koncesija ne postoji.');
        }
        $old = self::pathSlug($grp);
        $new = $slug === '' ? $grp : $slug;
        if ($slug !== '' && !Support::validSlug($slug)) {
            throw new InvalidArgumentException("Neispravna oznaka u adresi '$slug' (mala slova, brojke i crtica izmedju, 2-60 znakova).");
        }
        if ($new === $old) {
            return;
        }
        // jedinstvenost medju svim javnim putanjama (tudji slug ili tudji GRP bez sluga)
        foreach (Db::all('SELECT grp, slug FROM concessions WHERE grp <> ?', [$grp]) as $o) {
            $eff = ($o['slug'] !== null && $o['slug'] !== '') ? $o['slug'] : $o['grp'];
            if ($eff === $new) {
                throw new InvalidArgumentException("Oznaka '$new' vec koristi druga koncesija.");
            }
        }
        $root = Publisher::webroot();
        if (self::validPath($old) && is_dir("$root/$old")) {
            if (is_dir("$root/$new")) {
                throw new InvalidArgumentException("Mapa '$new' vec postoji u webrootu.");
            }
            if (!@rename("$root/$old", "$root/$new")) {
                throw new InvalidArgumentException('Ne mogu premjestiti mapu koncesije.');
            }
        }
        Db::run('UPDATE concessions SET slug = ? WHERE grp = ?', [$slug === '' ? null : $slug, $grp]);
    }

    public static function ensureConcession(string $grp, string $naziv = ''): void
    {
        if (!Support::validGrp($grp)) {
            throw new InvalidArgumentException("Neispravna oznaka koncesije '$grp' (a-z0-9, 2-8 znakova).");
        }
        if (Db::val('SELECT COUNT(*) FROM concessions WHERE grp = ?', [$grp]) == 0) {
            if (Db::val('SELECT COUNT(*) FROM concessions WHERE slug = ?', [$grp]) > 0) {
                throw new InvalidArgumentException("Oznaka '$grp' vec je javna adresa druge koncesije.");
            }
            Db::run('INSERT INTO concessions (grp, naziv, created_at) VALUES (?,?,?)', [$grp, $naziv !== '' ? $naziv : strtoupper($grp), Clock::utcStamp()]);
        }
    }

    /**
     * @param array<string,mixed> $d
     * @return int id
     */
    public static function create(array $d): int
    {
        $grp = strtolower(trim((string)($d['grp'] ?? '')));
        $betnr = (int)($d['betnr'] ?? 0);
        $naziv = trim((string)($d['naziv'] ?? ''));
        if ($betnr < 1 || $betnr > 99999) {
            throw new InvalidArgumentException('BETNR mora biti broj 1-99999.');
        }
        if ($naziv === '' || mb_strlen($naziv) > 200) {
            throw new InvalidArgumentException('Naziv je obavezan (max 200 znakova).');
        }
        self::ensureConcession($grp);
        if (self::find($grp, $betnr) !== null) {
            throw new InvalidArgumentException("Lokacija $grp/$betnr vec postoji.");
        }
        return Db::insert(
            'INSERT INTO locations (grp, betnr, naziv, pravni_naziv, adresa, oznaka_objekta, oblik_mjesta, feed_dijelovi, feed_vozila, feed_radovi, active, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,1,?)',
            [
                $grp, $betnr, $naziv,
                self::nullable($d['pravni_naziv'] ?? null, 200), self::nullable($d['adresa'] ?? null, 300),
                self::nullable($d['oznaka_objekta'] ?? null, 50), self::nullable($d['oblik_mjesta'] ?? null, 50),
                array_key_exists('feed_dijelovi', $d) && empty($d['feed_dijelovi']) ? 0 : 1,
                array_key_exists('feed_vozila', $d) && empty($d['feed_vozila']) ? 0 : 1,
                array_key_exists('feed_radovi', $d) && empty($d['feed_radovi']) ? 0 : 1,
                Clock::utcStamp(),
            ]
        );
    }

    /** @param array<string,mixed> $d */
    public static function update(int $id, array $d): void
    {
        $naziv = trim((string)($d['naziv'] ?? ''));
        if ($naziv === '' || mb_strlen($naziv) > 200) {
            throw new InvalidArgumentException('Naziv je obavezan (max 200 znakova).');
        }
        Db::run(
            'UPDATE locations SET naziv=?, pravni_naziv=?, adresa=?, oznaka_objekta=?, oblik_mjesta=?, feed_dijelovi=?, feed_vozila=?, feed_radovi=?, active=? WHERE id=?',
            [
                $naziv,
                self::nullable($d['pravni_naziv'] ?? null, 200), self::nullable($d['adresa'] ?? null, 300),
                self::nullable($d['oznaka_objekta'] ?? null, 50), self::nullable($d['oblik_mjesta'] ?? null, 50),
                empty($d['feed_dijelovi']) ? 0 : 1, empty($d['feed_vozila']) ? 0 : 1, empty($d['feed_radovi']) ? 0 : 1, empty($d['active']) ? 0 : 1,
                $id,
            ]
        );
    }

    /**
     * Trajno brise lokaciju: objavljene datoteke (i arhivu), zapise uploada, ukida kljuceve vezane uz tu lokaciju
     * i, ako koncesija ostane bez lokacija, brise i nju. Poziva se iz CLI-ja (bin/remove-location.php).
     * @return array{files:int, concession_removed:bool}
     */
    public static function delete(int $id): array
    {
        $loc = self::byId($id);
        if ($loc === null) {
            throw new InvalidArgumentException('Lokacija ne postoji.');
        }
        $grp = (string)$loc['grp'];
        $path = self::pathSlug($grp);
        $files = 0;
        if (self::validPath($path)) {
            $base = Publisher::webroot() . '/' . $path . '/' . (int)$loc['betnr'];
            if (is_dir($base)) {
                $files = self::removeTree($base);
            }
        }
        Db::run('DELETE FROM uploads WHERE location_id = ?', [$id]);
        Db::run('UPDATE api_keys SET revoked_at = ? WHERE location_id = ? AND revoked_at IS NULL', [Clock::utcStamp(), $id]);
        Db::run('DELETE FROM locations WHERE id = ?', [$id]);
        $removed = false;
        if ((int)Db::val('SELECT COUNT(*) FROM locations WHERE grp = ?', [$grp]) === 0) {
            Db::run('DELETE FROM concessions WHERE grp = ?', [$grp]);
            $removed = true;
            if (self::validPath($path) && is_dir(Publisher::webroot() . '/' . $path)) {
                self::removeTree(Publisher::webroot() . '/' . $path);
            }
        }
        return ['files' => $files, 'concession_removed' => $removed];
    }

    /** Rekurzivno brise mapu (samo unutar webroota); vraca broj obrisanih datoteka. */
    private static function removeTree(string $dir): int
    {
        $root = realpath(Publisher::webroot());
        $real = realpath($dir);
        if ($root === false || $real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Odbijeno: putanja je izvan webroota.');
        }
        $n = 0;
        foreach (scandir($real) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $real . DIRECTORY_SEPARATOR . $f;
            if (is_dir($p) && !is_link($p)) {
                $n += self::removeTree($p);
            } else {
                @unlink($p);
                $n++;
            }
        }
        @rmdir($real);
        return $n;
    }

    private static function nullable(mixed $v, int $max): ?string
    {
        $s = trim((string)$v);
        if ($s === '') {
            return null;
        }
        if (mb_strlen($s) > $max) {
            throw new InvalidArgumentException("Vrijednost je preduga (max $max).");
        }
        return $s;
    }

    /**
     * Otvara CSV (razdjelnik ';', UTF-8, zaglavlje) i vraca [handle, mapa naziv_stupca => indeks].
     * @param list<string> $required @param list<string> $optional
     * @return array{0:resource, 1:array<string,int>}
     */
    private static function openCsv(string $path, array $required, array $optional): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new InvalidArgumentException('Datoteka se ne moze citati.');
        }
        if (fread($fh, 3) !== "\xEF\xBB\xBF") {
            rewind($fh);
        }
        $hdr = fgetcsv($fh, 0, ';', '"', '');
        $col = [];
        foreach (($hdr === false ? [] : $hdr) as $i => $h) {
            $name = strtolower(trim((string)$h));
            if ($name === '') {
                continue;
            }
            if (!in_array($name, $required, true) && !in_array($name, $optional, true)) {
                fclose($fh);
                throw new InvalidArgumentException("Nepoznat stupac '$name'. Dopusteno: " . implode(', ', array_merge($required, $optional)));
            }
            $col[$name] = $i;
        }
        foreach ($required as $name) {
            if (!isset($col[$name])) {
                fclose($fh);
                throw new InvalidArgumentException("Nedostaje obavezni stupac '$name'. Zaglavlje mora sadrzavati: " . implode(', ', $required));
            }
        }
        return [$fh, $col];
    }

    /** @param list<string|null> $row @param array<string,int> $col */
    private static function cell(array $row, array $col, string $name): string
    {
        return isset($col[$name]) ? trim((string)($row[$col[$name]] ?? '')) : '';
    }

    private static function yesNo(string $v, string $what, int $line): ?int
    {
        $v = strtolower($v);
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['da', '1', 'true', 'x'], true)) {
            return 1;
        }
        if (in_array($v, ['ne', '0', 'false'], true)) {
            return 0;
        }
        throw new InvalidArgumentException("Redak $line: '$what' mora biti da ili ne (dobiveno '$v').");
    }

    /**
     * Uvoz koncesija (razdjelnik ';'). Obavezno: grp, naziv. Neobavezno: slug, web_url.
     * Postojecim koncesijama azurira naziv i (ako je upisano) slug i web_url, nepostojece stvara. Prazna celija = ne dira.
     * Cijeli uvoz je jedna transakcija: pri gresci nista se ne sprema.
     * @return array{created:int, updated:int}
     */
    public static function importConcessionsCsv(string $path): array
    {
        [$fh, $col] = self::openCsv($path, ['grp', 'naziv'], ['slug', 'web_url']);
        $created = $updated = 0;
        $line = 1;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            while (($r = fgetcsv($fh, 0, ';', '"', '')) !== false) {
                $line++;
                if ($r === [null]) {
                    continue;
                }
                $grp = strtolower(self::cell($r, $col, 'grp'));
                if ($grp === '') {
                    continue;
                }
                $naziv = self::cell($r, $col, 'naziv');
                if ($naziv === '' || mb_strlen($naziv) > 200) {
                    throw new InvalidArgumentException("Redak $line ($grp): naziv je obavezan (max 200 znakova).");
                }
                if (Db::val('SELECT COUNT(*) FROM concessions WHERE grp = ?', [$grp]) > 0) {
                    Db::run('UPDATE concessions SET naziv = ? WHERE grp = ?', [$naziv, $grp]);
                    $updated++;
                } else {
                    self::ensureConcession($grp, $naziv);
                    $created++;
                }
                $url = self::cell($r, $col, 'web_url');
                if ($url !== '') {
                    if (preg_match('#^https?://[^\s<>"\']{3,280}$#i', $url) !== 1) {
                        throw new InvalidArgumentException("Redak $line ($grp): web_url mora poceti s http:// ili https://");
                    }
                    Db::run('UPDATE concessions SET web_url = ? WHERE grp = ?', [$url, $grp]);
                }
                $slug = self::cell($r, $col, 'slug');
                if ($slug !== '') {
                    self::setSlug($grp, $slug);
                }
            }
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t instanceof InvalidArgumentException ? $t : new InvalidArgumentException($t->getMessage(), 0, $t);
        } finally {
            fclose($fh);
        }
        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Uvoz lokacija (razdjelnik ';'). Obavezno: grp, betnr, naziv. Neobavezno: pravni_naziv, adresa, oblik_mjesta,
     * oznaka_objekta, dijelovi, vozila, radovi (da/ne). Nove lokacije stvara; postojecima azurira upisane (neprazne)
     * stupce; ako je zaglavlje samo grp;betnr;naziv, postojece preskace. Prazna celija = ne dira.
     * Cijeli uvoz je jedna transakcija: pri gresci nista se ne sprema.
     * @return array{created:int, updated:int, skipped:int}
     */
    public static function importCsv(string $path): array
    {
        $optional = ['pravni_naziv', 'adresa', 'oblik_mjesta', 'oznaka_objekta', 'dijelovi', 'vozila', 'radovi'];
        [$fh, $col] = self::openCsv($path, ['grp', 'betnr', 'naziv'], $optional);
        $hasExtra = count(array_intersect(array_keys($col), $optional)) > 0;
        $created = $updated = $skipped = 0;
        $line = 1;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            while (($r = fgetcsv($fh, 0, ';', '"', '')) !== false) {
                $line++;
                if ($r === [null]) {
                    continue;
                }
                $grp = strtolower(self::cell($r, $col, 'grp'));
                $betnrRaw = self::cell($r, $col, 'betnr');
                if ($grp === '' && $betnrRaw === '') {
                    continue;
                }
                if (!ctype_digit($betnrRaw)) {
                    throw new InvalidArgumentException("Redak $line: betnr mora biti broj (dobiveno '$betnrRaw').");
                }
                $betnr = (int)$betnrRaw;
                $d = [
                    'grp' => $grp, 'betnr' => $betnr, 'naziv' => self::cell($r, $col, 'naziv'),
                    'pravni_naziv' => self::cell($r, $col, 'pravni_naziv'), 'adresa' => self::cell($r, $col, 'adresa'),
                    'oblik_mjesta' => self::cell($r, $col, 'oblik_mjesta'), 'oznaka_objekta' => self::cell($r, $col, 'oznaka_objekta'),
                ];
                $feeds = [];
                foreach (['dijelovi', 'vozila', 'radovi'] as $f) {
                    $v = self::yesNo(self::cell($r, $col, $f), $f, $line);
                    if ($v !== null) {
                        $feeds['feed_' . $f] = $v;
                    }
                }
                try {
                    $existing = self::find($grp, $betnr);
                    if ($existing === null) {
                        self::create(array_filter($d, static fn($v) => $v !== '') + $feeds);
                        $created++;
                    } elseif (!$hasExtra) {
                        $skipped++;
                    } else {
                        $set = [];
                        $par = [];
                        foreach (['naziv', 'pravni_naziv', 'adresa', 'oblik_mjesta', 'oznaka_objekta'] as $f) {
                            if ($d[$f] !== '') {
                                $set[] = "$f = ?";
                                $par[] = self::nullable($d[$f], $f === 'adresa' ? 300 : ($f === 'naziv' || $f === 'pravni_naziv' ? 200 : 50));
                            }
                        }
                        foreach ($feeds as $k => $v) {
                            $set[] = "$k = ?";
                            $par[] = $v;
                        }
                        if ($set !== []) {
                            $par[] = $existing['id'];
                            Db::run('UPDATE locations SET ' . implode(', ', $set) . ' WHERE id = ?', $par);
                        }
                        $updated++;
                    }
                } catch (InvalidArgumentException $e) {
                    throw new InvalidArgumentException("Redak $line ($grp/$betnr): " . $e->getMessage(), 0, $e);
                }
            }
            $pdo->commit();
        } catch (\Throwable $t) {
            $pdo->rollBack();
            throw $t instanceof InvalidArgumentException ? $t : new InvalidArgumentException($t->getMessage(), 0, $t);
        } finally {
            fclose($fh);
        }
        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }
}
