<?php
declare(strict_types=1);

namespace Cjenik;

/**
 * Specifikacija i strogi validator CSV cjenika (Odluka o objavi cjenika, NN 101/2026, cl. III i IV).
 * Format: UTF-8, razdjelnik ';', navodnici '"', decimalna TOCKA, zaglavlje tocno kao u FEEDS.
 * Sluzbena CSV specifikacija ne postoji, ovo je nas izbor (docs/CSV-spec.md).
 */
final class CsvSpec
{
    public const DELIM = ';';

    /** Stupci cjenika PROIZVODA (Odluka cl. III): vozila i dijelovi. kolona => [tip, obavezno, maxDuljina] */
    public const PROIZVOD_COLUMNS = [
        'naziv' => ['text', true, 300],
        'sifra' => ['text', true, 100],
        'marka' => ['text', false, 100],
        'neto_kolicina' => ['decimal', false, 0],
        'jedinica_mjere' => ['text', false, 20],
        'cijena_po_jedinici_mjere' => ['money', false, 0],
        'maloprodajna_cijena' => ['money', true, 0],
        'posebni_oblik_prodaje' => ['yesno', true, 0],
        'naziv_posebnog_oblika_prodaje' => ['text', false, 200],
        'bazna_cijena' => ['money', true, 0],
        'sidrena_cijena' => ['money', true, 0],
        'barkod' => ['barcode', false, 0],
        'dostupnost' => ['avail', true, 0],
        'najniza_cijena_30_dana' => ['money', true, 0],
    ];

    /** Stupci cjenika USLUGA (Odluka cl. IV): radovi. */
    public const USLUGA_COLUMNS = [
        'naziv_usluge' => ['text', true, 300],
        'maloprodajna_cijena' => ['money', true, 0],
        'posebni_oblik_prodaje' => ['yesno', true, 0],
        'naziv_posebnog_oblika_prodaje' => ['text', false, 200],
        'bazna_cijena' => ['money', true, 0],
        'sidrena_cijena' => ['money', true, 0],
        'najniza_cijena_30_dana' => ['money', true, 0],
    ];

    /**
     * Dva cjenika po lokaciji (specifikacija "Sidrene cijene Hrvatska s 17.11.2026."): 'proizvodi' (vozila i
     * rezervni dijelovi u istoj datoteci, ista struktura) i 'radovi' (usluge).
     */
    public const FEEDS = [
        'proizvodi' => ['label' => 'Vozila i proizvodi', 'kind' => 'proizvod', 'columns' => self::PROIZVOD_COLUMNS],
        'radovi' => ['label' => 'Radovi (usluge)', 'kind' => 'usluga', 'columns' => self::USLUGA_COLUMNS],
    ];

    public static function isFeed(string $feed): bool
    {
        return isset(self::FEEDS[$feed]);
    }

    /** @return list<string> */
    public static function header(string $feed): array
    {
        return array_keys(self::FEEDS[$feed]['columns']);
    }

    /**
     * @return array{ok:bool, rows:int, errors:list<string>}
     */
    public static function validate(string $path, string $feed, int $maxRows = 2000000): array
    {
        $errors = [];
        $add = static function (string $m) use (&$errors): void {
            if (count($errors) < 20) {
                $errors[] = $m;
            }
        };
        if (!self::isFeed($feed)) {
            return ['ok' => false, 'rows' => 0, 'errors' => ['Nepoznat feed.']];
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'rows' => 0, 'errors' => ['Datoteka se ne moze citati.']];
        }
        if (fread($fh, 3) !== "\xEF\xBB\xBF") {
            rewind($fh);
        }
        $cols = self::FEEDS[$feed]['columns'];
        $expected = array_keys($cols);

        $hdr = fgetcsv($fh, 0, self::DELIM, '"', '');
        if ($hdr === false || $hdr === [null]) {
            fclose($fh);
            return ['ok' => false, 'rows' => 0, 'errors' => ['Datoteka je prazna (nema zaglavlja).']];
        }
        $hdr = array_map(static fn($h) => strtolower(trim((string)$h)), $hdr);
        if ($hdr !== $expected) {
            fclose($fh);
            return ['ok' => false, 'rows' => 0, 'errors' => [
                'Zaglavlje nije ispravno. Ocekivano: ' . implode(';', $expected) . ' | Dobiveno: ' . implode(';', $hdr),
            ]];
        }

        $n = 0;
        $line = 1;
        while (($row = fgetcsv($fh, 0, self::DELIM, '"', '')) !== false) {
            $line++;
            if ($row === [null]) {
                continue; // prazan redak
            }
            $n++;
            if ($n > $maxRows) {
                $add("Previse redaka (max $maxRows).");
                break;
            }
            if (count($row) !== count($expected)) {
                $add("Redak $line: ocekivano " . count($expected) . ' stupaca, dobiveno ' . count($row) . '.');
                continue;
            }
            $vals = array_combine($expected, $row);
            foreach ($cols as $name => [$type, $required, $max]) {
                $err = self::checkValue((string)$vals[$name], $type, $required, $max);
                if ($err !== null) {
                    $add("Redak $line, stupac $name: $err");
                }
            }
            if ($vals['posebni_oblik_prodaje'] === 'da' && trim((string)$vals['naziv_posebnog_oblika_prodaje']) === '') {
                $add("Redak $line: posebni_oblik_prodaje=da zahtijeva naziv_posebnog_oblika_prodaje.");
            }
            if (isset($vals['cijena_po_jedinici_mjere']) && $vals['cijena_po_jedinici_mjere'] !== '' && trim((string)$vals['jedinica_mjere']) === '') {
                $add("Redak $line: cijena_po_jedinici_mjere zahtijeva jedinica_mjere.");
            }
        }
        fclose($fh);
        if ($n === 0) {
            $add('Datoteka nema nijedan redak podataka.');
        }
        return ['ok' => $errors === [], 'rows' => $n, 'errors' => $errors];
    }

    private static function checkValue(string $v, string $type, bool $required, int $max): ?string
    {
        if ($v === '') {
            return $required ? 'obavezno polje je prazno' : null;
        }
        if (!mb_check_encoding($v, 'UTF-8')) {
            return 'nije valjan UTF-8';
        }
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $v) === 1) {
            return 'sadrzi kontrolne znakove ili novi redak';
        }
        switch ($type) {
            case 'text':
                if (mb_strlen($v) > $max) {
                    return "predugo (max $max znakova)";
                }
                if (preg_match('/^[=+@\t\r]/', $v) === 1) {
                    return 'ne smije poceti znakom = + @ (CSV/formula injection)';
                }
                return null;
            case 'money':
                if (str_contains($v, ',')) {
                    return 'koristi decimalnu TOCKU, ne zarez';
                }
                return preg_match('/^\d{1,9}(\.\d{1,4})?$/', $v) === 1 ? null : 'neispravan iznos (npr. 1234.50)';
            case 'decimal':
                if (str_contains($v, ',')) {
                    return 'koristi decimalnu TOCKU, ne zarez';
                }
                return preg_match('/^\d{1,9}(\.\d{1,4})?$/', $v) === 1 ? null : 'neispravan broj (npr. 0.75)';
            case 'yesno':
                return ($v === 'da' || $v === 'ne') ? null : "dopusteno 'da' ili 'ne'";
            case 'avail':
                return ($v === 'dostupno' || $v === 'nedostupno') ? null : "dopusteno 'dostupno' ili 'nedostupno'";
            case 'barcode':
                return preg_match('/^\d{8,14}$/', $v) === 1 ? null : 'barkod mora imati 8-14 znamenki';
        }
        return null;
    }
}
