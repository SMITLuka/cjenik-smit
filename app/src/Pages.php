<?php
declare(strict_types=1);

namespace Cjenik;

use DateTimeImmutable;
use DateTimeZone;

/** Generira staticke javne stranice (HTML) — posluzuju se bez PHP-a. */
final class Pages
{
    public static function renderAll(): void
    {
        $root = Publisher::webroot();
        $concessions = Locations::concessions();
        $locs = Locations::all(true);
        $byGrp = [];
        foreach ($locs as $l) {
            if (!Support::validGrp((string)$l['grp'])) {
                continue; // nikad ne pisati izvan ocekivane strukture, cak ni ako je baza rucno izmijenjena
            }
            $byGrp[$l['grp']][] = $l;
        }
        $names = [];
        foreach ($concessions as $c) {
            $names[$c['grp']] = $c;
        }

        $stamp = Clock::now()->format('d.m.Y. H:i');
        $common = ['operator' => (array)Config::get('operator', []), 'generated' => $stamp, 'baseUrl' => rtrim((string)Config::get('base_url', ''), '/')];

        // IZOLACIJA PO KONCESIJI: korijen i opce stranice ne sadrze popis ni poveznice na koncesije; stranice
        // jedne koncesije vode samo unutar te koncesije (nema "natrag" na popis svih). Nema ni sitemap.xml.
        $neutral = ['brand' => 'Cjenici', 'brandHref' => null, 'nav' => [], 'crumbs' => []];

        $content = View::render('public/index', $common);
        Publisher::writeAtomic($root . '/index.html', View::render('public/layout', $common + $neutral + ['title' => 'Cjenici', 'content' => $content]));

        // Opis formata (opca stranica, bez poveznica na koncesije)
        $content = View::render('public/format', $common + ['feeds' => CsvSpec::FEEDS]);
        Publisher::writeAtomic($root . '/format.html', View::render('public/layout', $common + $neutral + ['title' => 'Opis formata CSV datoteka', 'content' => $content]));

        foreach ($byGrp as $grp => $list) {
            $slug = Locations::pathSlug((string)$grp); // javna putanja: slug ako postoji, inace GRP
            if (!Locations::validPath($slug)) {
                continue;
            }
            $c = $names[$grp] ?? ['grp' => $grp, 'naziv' => strtoupper($grp), 'web_url' => null];
            $scoped = ['brand' => (string)$c['naziv'], 'brandHref' => "/$slug/", 'nav' => [['Format CSV-a', "/$slug/format.html"]], 'slug' => $slug];
            $content = View::render('public/group', $common + $scoped + ['concession' => $c, 'locations' => $list]);
            Publisher::writeAtomic($root . "/$slug/index.html", View::render('public/layout', $common + $scoped + ['title' => (string)$c['naziv'], 'content' => $content, 'crumbs' => []]));
            $fmt = View::render('public/format', $common + ['feeds' => CsvSpec::FEEDS]);
            Publisher::writeAtomic($root . "/$slug/format.html", View::render('public/layout', $common + $scoped + ['title' => 'Opis formata CSV datoteka', 'content' => $fmt, 'crumbs' => [[(string)$c['naziv'], "/$slug/"]]]));

            foreach ($list as $loc) {
                $feeds = [];
                foreach (Locations::feeds($loc) as $feed) {
                    $ups = Db::all(
                        'SELECT price_date, rows_count, bytes, source, created_at, file_name FROM uploads WHERE location_id = ? AND feed = ? ORDER BY price_date DESC',
                        [$loc['id'], $feed]
                    );
                    foreach ($ups as &$u) {
                        $u['file_name'] = Publisher::fileNameOf($u);
                        $u['download'] = $u['file_name'];
                    }
                    unset($u);
                    $feeds[$feed] = ['label' => CsvSpec::FEEDS[$feed]['label'], 'uploads' => $ups];
                }
                $content = View::render('public/location', $common + $scoped + ['concession' => $c, 'loc' => $loc, 'feeds' => $feeds, 'today' => Clock::today()]);
                $title = $loc['naziv'] . ' (' . strtoupper((string)$loc['grp']) . '/' . $loc['betnr'] . ')';
                Publisher::writeAtomic(
                    $root . "/$slug/" . (int)$loc['betnr'] . '/index.html',
                    View::render('public/layout', $common + $scoped + ['title' => $title, 'content' => $content, 'crumbs' => [[(string)$c['naziv'], "/$slug/"]]])
                );
            }
        }

        // robots.txt namjerno ne nabraja koncesije (ni sitemap), da popis ne bude izlozen na jednom mjestu.
        Publisher::writeAtomic($root . '/robots.txt', "User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\n");
        $old = $root . '/sitemap.xml';
        if (is_file($old)) {
            @unlink($old);
        }
    }

    /**
     * Naziv datoteke prema nacrtu Pravilnika o nacinu objave cjenika (cl. 6(2)): oblik, adresa i oznaka prodajnog
     * mjesta, broj pohrane te vremenska oznaka (datum i vrijeme slanja). Dodani su naziv objekta i vrsta cjenika
     * (proizvodi/radovi) radi citljivosti i razlikovanja. Broj pohrane = redni broj objave (PRETPOSTAVKA dok
     * se pojam ne pojasni). Oblik = polje "oblik prodajnog mjesta" lokacije (neobavezno).
     * Primjer: rb-auto-zagreb_salon_ulica-1-zagreb_kb521_proizvodi_000007_20261117070000.csv
     */
    public static function downloadName(array $loc, string $feed, int $seq, string $createdUtc): string
    {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $createdUtc, new DateTimeZone('UTC'));
        $ts = $dt ? $dt->setTimezone(new DateTimeZone((string)Config::get('timezone', 'Europe/Zagreb')))->format('YmdHis') : '00000000000000';
        $cut = static fn(string $s): string => rtrim(substr($s, 0, 40), '-');
        $parts = [
            $cut(self::slug((string)($loc['pravni_naziv'] ?: $loc['naziv']))),
            $cut(self::slug((string)($loc['oblik_mjesta'] ?? ''))),
            $cut(self::slug((string)($loc['adresa'] ?? ''))),
            self::slug((string)($loc['oznaka_objekta'] ?: strtoupper((string)$loc['grp']) . $loc['betnr'])),
            self::slug($feed),
            str_pad((string)$seq, 6, '0', STR_PAD_LEFT),
            $ts,
        ];
        return implode('_', array_filter($parts, static fn($p) => $p !== '')) . '.csv';
    }

    public static function slug(string $s): string
    {
        $s = strtr(mb_strtolower($s), ['č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'dj']);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        return trim($s, '-');
    }
}
