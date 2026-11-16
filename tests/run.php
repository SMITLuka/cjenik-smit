<?php
declare(strict_types=1);

/**
 * Testovi bez vanjskih ovisnosti:  php tests/run.php
 * Koriste SQLite u memoriji i privremeni webroot.
 */

define('CJENIK_TESTING', true);
require dirname(__DIR__) . '/app/bootstrap.php';

use Cjenik\Api;
use Cjenik\Clock;
use Cjenik\Config;
use Cjenik\Crypto;
use Cjenik\CsvSpec;
use Cjenik\Db;
use Cjenik\Hmac;
use Cjenik\Ip;
use Cjenik\Keys;
use Cjenik\Locations;
use Cjenik\Maintenance;
use Cjenik\Pages;
use Cjenik\Publisher;
use Cjenik\Schema;
use Cjenik\Totp;

$pass = 0;
$fail = 0;
function t(string $name, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   $name\n";
    } else {
        $fail++;
        echo "  FAIL $name" . ($extra !== '' ? " -> $extra" : '') . "\n";
    }
}

function rrmdir(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        is_dir("$d/$f") ? rrmdir("$d/$f") : @unlink("$d/$f");
    }
    @rmdir($d);
}

function freshEnv(): string
{
    $root = sys_get_temp_dir() . '/cjenik-test-' . bin2hex(random_bytes(4));
    mkdir($root . '/web', 0777, true);
    mkdir($root . '/storage', 0777, true);
    Config::load([
        'app_key' => Crypto::generateKey(),
        'webroot' => $root . '/web',
        'storage' => $root . '/storage',
        'base_url' => 'https://cjenik.test',
        'timezone' => 'Europe/Zagreb',
        'operator' => ['name' => 'Test', 'contact' => ''],
        'ingest_allowed_ips' => [],
        'require_https' => true,
        'timestamp_window_seconds' => 300,
        'retention_days' => 30,
        'max_rows' => 1000,
        'watchdog' => ['alert_at' => '07:40', 'carryover_at' => '07:55', 'weekdays_only' => false],
    ]);
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    \Cjenik\Db::set($pdo);
    Schema::migrate();
    Clock::set(new DateTimeImmutable('2026-11-17 07:00:00', new DateTimeZone('Europe/Zagreb')));
    return $root;
}

const PROIZVODI_CSV = "naziv;sifra;marka;neto_kolicina;jedinica_mjere;cijena_po_jedinici_mjere;maloprodajna_cijena;posebni_oblik_prodaje;naziv_posebnog_oblika_prodaje;bazna_cijena;sidrena_cijena;barkod;dostupnost;najniza_cijena_30_dana\n"
    . "Filter ulja;F-100;Mazda;;kom;;12.50;ne;;12.50;12.50;3850000000011;dostupno;12.50\n"
    . "Ulje 5W30;U-5;Castrol;1;l;8.00;40.00;da;Akcija studeni;45.00;45.00;;dostupno;38.00\n";
const USLUGE_CSV = "naziv_usluge;maloprodajna_cijena;posebni_oblik_prodaje;naziv_posebnog_oblika_prodaje;bazna_cijena;sidrena_cijena;najniza_cijena_30_dana\n"
    . "Sat rada servisa;55.00;ne;;55.00;55.00;55.00\n";


/** Putanja datoteke objave za (grp, betnr, feed, datum) prema file_name iz baze. */
function upFile(string $root, string $grp, int $betnr, string $feed, string $date): string
{
    $loc = Locations::find($grp, $betnr);
    $n = Db::val('SELECT file_name FROM uploads WHERE location_id = ? AND feed = ? AND price_date = ?', [$loc['id'], $feed, $date]);
    return $root . '/web/' . Locations::pathSlug($grp) . '/' . $betnr . '/' . $feed . '/' . ($n ?? 'NEMA');
}
function tmpCsv(string $content): string
{
    $f = tempnam(sys_get_temp_dir(), 'csvt');
    file_put_contents($f, $content);
    return $f;
}

/**
 * Potpisani zahtjev. $mut moze pokvariti pojedino zaglavlje.
 * @param array<string,string> $override
 * @return array{0:int,1:array<string,mixed>}
 */
function call(string $keyId, string $secret, string $method, string $uri, string $body = '', array $override = [], bool $gzip = false, string $ip = '203.0.113.5', ?string $signUri = null): array
{
    $sent = $gzip ? (string)gzencode($body) : $body;
    $sha = hash('sha256', $sent);
    $ts = (string)Clock::now()->getTimestamp();
    $nonce = bin2hex(random_bytes(12));
    $server = [
        'REMOTE_ADDR' => $ip, 'HTTPS' => 'on', 'REQUEST_URI' => $uri,
        'HTTP_X_KEY_ID' => $keyId, 'HTTP_X_TIMESTAMP' => $ts, 'HTTP_X_NONCE' => $nonce, 'HTTP_X_CONTENT_SHA256' => $sha,
    ];
    if ($gzip) {
        $server['HTTP_CONTENT_ENCODING'] = 'gzip';
    }
    $server = array_merge($server, $override);
    if (!isset($server['HTTP_X_SIGNATURE'])) {
        $server['HTTP_X_SIGNATURE'] = Hmac::sign($secret, $method, $signUri ?? $uri,$server['HTTP_X_TIMESTAMP'], $server['HTTP_X_NONCE'], $server['HTTP_X_CONTENT_SHA256']);
    }
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $sent);
    rewind($stream);
    $r = Api::process($server, $method, $uri, $stream);
    fclose($stream);
    return $r;
}

// ---------------------------------------------------------------- Ip
echo "Ip\n";
// IP klijenta iza proxyja: nikad prva adresa iz X-Forwarded-For (krivotvorljiva)
Config::load(['client_ip_header' => 'HTTP_X_FORWARDED_FOR']);
t('iza proxyja: zadnja adresa (dodao proxy)', Ip::client(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']) === '203.0.113.9');
t('krivotvoreni XFF: klijent salje allowed IP, proxy dodaje pravu', Ip::client(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '10.0.0.5, 198.51.100.7']) === '198.51.100.7');
Config::load(['client_ip_header' => 'HTTP_X_FORWARDED_FOR', 'client_ip_hops' => 2]);
t('dva proxyja: predzadnja adresa', Ip::client(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '10.0.0.5, 198.51.100.7, 172.20.0.3']) === '198.51.100.7');
t('manje adresa nego hopova: REMOTE_ADDR', Ip::client(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']) === '172.18.0.2');
t('nevaljana adresa u headeru: REMOTE_ADDR', Ip::client(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => 'nije-ip']) === '172.18.0.2');
Config::load(['client_ip_header' => null]);
t('bez konfiguriranog headera se zaglavlje ignorira', Ip::client(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '10.0.0.5']) === '172.18.0.2');
t('tocna IP', Ip::allowed('10.0.0.5', ['10.0.0.5']));
t('CIDR /24 unutra', Ip::allowed('192.168.1.77', ['192.168.1.0/24']));
t('CIDR /24 izvan', !Ip::allowed('192.168.2.1', ['192.168.1.0/24']));
t('CIDR /20 granica', Ip::allowed('10.0.15.255', ['10.0.0.0/20']) && !Ip::allowed('10.0.16.0', ['10.0.0.0/20']));
t('IPv6 CIDR', Ip::allowed('2001:db8::1', ['2001:db8::/32']) && !Ip::allowed('2001:db9::1', ['2001:db8::/32']));
t('prazna lista dopusta sve', Ip::allowed('1.2.3.4', []));
t('nepoznata IP odbijena', !Ip::allowed('8.8.8.8', ['10.0.0.0/8']));
t('neispravan unos nije match', !Ip::allowed('not-an-ip', ['10.0.0.0/8']));

// ---------------------------------------------------------------- Totp (RFC 6238 test vektori, SHA1)
echo "Totp\n";
$rfc = Totp::base32Encode('12345678901234567890');
t('RFC6238 T=59', Totp::code($rfc, intdiv(59, 30)) === '287082');
t('RFC6238 T=1111111109', Totp::code($rfc, intdiv(1111111109, 30)) === '081804');
t('base32 roundtrip', Totp::base32Decode(Totp::base32Encode("abc\x00\xff")) === "abc\x00\xff");
$sec = Totp::generateSecret();
$step = intdiv(1700000000, 30);
t('verify prihvaca ispravan kod', Totp::verify($sec, Totp::code($sec, $step), 0, 1700000000) === $step);
t('verify odbija replay (lastStep)', Totp::verify($sec, Totp::code($sec, $step), $step, 1700000000) === null);
t('verify odbija pogresan kod', Totp::verify($sec, '000000', 0, 1700000000) === null || Totp::code($sec, $step) === '000000');
t('verify odbija nebrojeve', Totp::verify($sec, 'abcdef', 0, 1700000000) === null);

// ---------------------------------------------------------------- Crypto
echo "Crypto\n";
$root = freshEnv();
$enc = Crypto::encrypt('tajna-123');
t('encrypt/decrypt', Crypto::decrypt($enc) === 'tajna-123' && $enc !== 'tajna-123');
$tampered = base64_encode(substr((string)base64_decode($enc), 0, -1) . 'X');
$threw = false;
try {
    Crypto::decrypt($tampered);
} catch (Throwable) {
    $threw = true;
}
t('izmijenjen sifrat se odbija (GCM)', $threw);
rrmdir($root);

// ---------------------------------------------------------------- CsvSpec
echo "CsvSpec\n";
t('valjani dijelovi', CsvSpec::validate(tmpCsv(PROIZVODI_CSV), 'proizvodi')['ok']);
t('valjane radovi', CsvSpec::validate(tmpCsv(USLUGE_CSV), 'radovi')['ok']);
t('BOM je dopusten', CsvSpec::validate(tmpCsv("\xEF\xBB\xBF" . USLUGE_CSV), 'radovi')['ok']);
t('krivo zaglavlje', !CsvSpec::validate(tmpCsv("a;b\n1;2\n"), 'radovi')['ok']);
t('zarez kao decimala odbijen', !CsvSpec::validate(tmpCsv(str_replace('55.00;ne;;55.00', '55,00;ne;;55.00', USLUGE_CSV)), 'radovi')['ok']);
t('formula injection odbijen', !CsvSpec::validate(tmpCsv(str_replace('Sat rada servisa', '=HYPERLINK(1)', USLUGE_CSV)), 'radovi')['ok']);
t('prazan obavezni naziv odbijen', !CsvSpec::validate(tmpCsv(str_replace('Sat rada servisa', '', USLUGE_CSV)), 'radovi')['ok']);
t('nula redaka odbijena', !CsvSpec::validate(tmpCsv("naziv_usluge;maloprodajna_cijena;posebni_oblik_prodaje;naziv_posebnog_oblika_prodaje;bazna_cijena;sidrena_cijena;najniza_cijena_30_dana\n"), 'radovi')['ok']);
t('posebni oblik da bez naziva odbijen', !CsvSpec::validate(tmpCsv(str_replace('55.00;ne;;55.00', '55.00;da;;55.00', USLUGE_CSV)), 'radovi')['ok']);
t('krivi broj stupaca', !CsvSpec::validate(tmpCsv(str_replace('55.00;ne;;55.00', '55.00;ne;55.00', USLUGE_CSV)), 'radovi')['ok']);
t('barkod mora imati 8-14 znamenki', !CsvSpec::validate(tmpCsv(str_replace('3850000000011', '12ab', PROIZVODI_CSV)), 'proizvodi')['ok']);
t('dostupnost samo dostupno/nedostupno', !CsvSpec::validate(tmpCsv(str_replace('dostupno', 'moze', PROIZVODI_CSV)), 'proizvodi')['ok']);
t('cijena po jedinici trazi jedinicu', !CsvSpec::validate(tmpCsv(str_replace('Filter ulja;F-100;Mazda;;kom;;', 'Filter ulja;F-100;Mazda;;;5.00;', PROIZVODI_CSV)), 'proizvodi')['ok']);
$many = "naziv_usluge;maloprodajna_cijena;posebni_oblik_prodaje;naziv_posebnog_oblika_prodaje;bazna_cijena;sidrena_cijena;najniza_cijena_30_dana\n" . str_repeat("X;1.00;ne;;1.00;1.00;1.00\n", 6);
t('max_rows se postuje', !CsvSpec::validate(tmpCsv($many), 'radovi', 5)['ok']);

// ---------------------------------------------------------------- Locations / seed
echo "Locations\n";
$root = freshEnv();
$seed = dirname(__DIR__) . '/seed/locations.csv';
$r = Locations::importCsv($seed);
t('uvoz 50 lokacija (bez KI2 i lokacija bez prodaje dijelova)', $r['created'] === 50, json_encode($r));
$r2 = Locations::importCsv($seed);
t('ponovni uvoz preskace', $r2['created'] === 0 && $r2['skipped'] === 50);
t('dijakritike ocisceni', Locations::find('ka6', 1)['naziv'] === 'AC POREČ');
// prosireni uvoz lokacija (tablica za popunjavanje)
$ext = tmpCsv("grp;betnr;naziv;pravni_naziv;adresa;oblik_mjesta;oznaka_objekta;dijelovi;vozila;radovi\n"
    . "ka7;1;Roca;AUTO CENTAR ROCA d.o.o.;Ulica 5, Pula;salon;ROCA-1;da;ne;da\n"
    . "zz9;7;Nova lokacija;;Adresa 1;;;;;ne\n");
$re = Locations::importCsv($ext);
$roca = Locations::find('ka7', 1);
t('prosireni uvoz: azurira postojecu, dodaje novu', $re['created'] === 1 && $re['updated'] === 1 && $re['skipped'] === 0, json_encode($re));
t('prosireni uvoz: polja i feedovi upisani', $roca['pravni_naziv'] === 'AUTO CENTAR ROCA d.o.o.' && $roca['adresa'] === 'Ulica 5, Pula' && $roca['oblik_mjesta'] === 'salon' && $roca['oznaka_objekta'] === 'ROCA-1' && Locations::sources($roca) === ['dijelovi', 'radovi'] && Locations::feeds($roca) === ['proizvodi', 'radovi']);
t('prosireni uvoz: prazna celija ne brise postojece', Locations::importCsv(tmpCsv("grp;betnr;naziv;adresa\nka7;1;Roca;\n"))['updated'] === 1 && Locations::find('ka7', 1)['adresa'] === 'Ulica 5, Pula');
t('prosireni uvoz: nova lokacija ima iskljucen izvor radovi', Locations::sources(Locations::find('zz9', 7)) === ['dijelovi', 'vozila'] && Locations::feeds(Locations::find('zz9', 7)) === ['proizvodi']);
$before = (int)Db::val('SELECT COUNT(*) FROM locations');
$thrown = '';
try {
    Locations::importCsv(tmpCsv("grp;betnr;naziv;vozila\nka7;1;Roca;ne\nka6;1;AC;moze\n"));
} catch (InvalidArgumentException $ex) {
    $thrown = $ex->getMessage();
}
t('uvoz s greskom: poruka s brojem retka i nista se ne sprema (transakcija)', str_contains($thrown, 'Redak 3') && Locations::sources(Locations::find('ka7', 1)) === ['dijelovi', 'radovi'] && (int)Db::val('SELECT COUNT(*) FROM locations') === $before, $thrown);
$bad = '';
try {
    Locations::importCsv(tmpCsv("grp;betnr;naziv;nepoznato\nka7;1;Roca;x\n"));
} catch (InvalidArgumentException $ex) {
    $bad = $ex->getMessage();
}
t('uvoz: nepoznat stupac se odbija', str_contains($bad, "Nepoznat stupac 'nepoznato'"), $bad);
Db::run('DELETE FROM locations WHERE grp = ?', ['zz9']);
Db::run('DELETE FROM concessions WHERE grp = ?', ['zz9']);

$rc = Locations::importConcessionsCsv(dirname(__DIR__) . '/seed/concessions.csv');
$wu = Locations::importConcessionsCsv(tmpCsv("grp;naziv;web_url\nka4;Auto Kuća Gašperov d.o.o.;https://www.example.hr\n"));
t('uvoz koncesija: web_url se sprema', $wu['updated'] === 1 && Db::val('SELECT web_url FROM concessions WHERE grp = ?', ['ka4']) === 'https://www.example.hr');
$badUrl = '';
try {
    Locations::importConcessionsCsv(tmpCsv("grp;naziv;web_url\nka4;x;javascript:alert(1)\n"));
} catch (InvalidArgumentException $ex) {
    $badUrl = $ex->getMessage();
}
t('uvoz koncesija: opasan web_url se odbija', str_contains($badUrl, 'web_url'), $badUrl);
$names = array_column(Locations::concessions(), 'naziv', 'grp');
t('uvoz naziva koncesija (26, sve postojece azurirane)', $rc['created'] + $rc['updated'] === 26 && $rc['updated'] === 26, json_encode($rc));
t('KI2 nije u seedu', !isset($names['ki2']) && Locations::find('ki2', 1) === null);
t('KA4 = Auto Kuća Gašperov d.o.o. (velika slova kako su unesena)', ($names['ka4'] ?? '') === 'Auto Kuća Gašperov d.o.o.' && ($names['ka7'] ?? '') === 'AUTO CENTAR ROCA d.o.o.');
t('slug (adresa) ostaje malim slovima iako je naziv velikim', Locations::pathSlug('ka7') === 'autocentarroca' && (string)Db::val('SELECT slug FROM concessions WHERE grp = ?', ['ka7']) === 'autocentarroca');
t('ponovni uvoz je idempotentan', Locations::importConcessionsCsv(dirname(__DIR__) . '/seed/concessions.csv')['created'] === 0);
$threw = false;
try {
    Locations::create(['grp' => 'admin', 'betnr' => 1, 'naziv' => 'x']);
} catch (InvalidArgumentException) {
    $threw = true;
}
t('rezervirani slug (admin) odbijen', $threw);
$threw = false;
try {
    Locations::create(['grp' => '../x', 'betnr' => 1, 'naziv' => 'x']);
} catch (InvalidArgumentException) {
    $threw = true;
}
t('path traversal u grp odbijen', $threw);
rrmdir($root);

// ---------------------------------------------------------------- API
echo "Api\n";
$root = freshEnv();
Locations::create(['grp' => 'ka4', 'betnr' => 1, 'naziv' => 'Split <b>GA</b>', 'adresa' => 'Ulica 1, Split']);
Locations::create(['grp' => 'ka4', 'betnr' => 2, 'naziv' => 'Sibenik', 'feed_radovi' => 0]);
Locations::create(['grp' => 'kb1', 'betnr' => 1, 'naziv' => 'Druga koncesija']);
$global = Keys::create('global', null, null, 'agent');
$grpKey = Keys::create('group', 'ka4', null, 'ka4 only');
$locKey = Keys::create('location', null, (int)Locations::find('ka4', 1)['id'], 'ka4/1 only');
$u = '/api/v1/ka4/1/proizvodi';

[$st, $b] = call($global['key_id'], $global['secret'], 'GET', '/api/v1/ping');
t('ping s ispravnim potpisom', $st === 200 && $b['ok'] === true, json_encode($b));
[$st, $b] = call($global['key_id'], $global['secret'], 'PUT', $u, PROIZVODI_CSV);
t('PUT ispravan CSV -> 200', $st === 200 && $b['rows'] === 2, json_encode($b));
t('latest.csv i datirani postoje', is_file("$root/web/ka4/1/proizvodi/latest.csv") && is_file(upFile($root, 'ka4', 1, 'proizvodi', '2026-11-17')));
t('sadrzaj latest.csv identican', file_get_contents("$root/web/ka4/1/proizvodi/latest.csv") === PROIZVODI_CSV);
t('stranica lokacije generirana', is_file("$root/web/ka4/1/index.html") && is_file("$root/web/index.html") && is_file("$root/web/robots.txt"));
$html = (string)file_get_contents("$root/web/ka4/1/index.html");
$rootHtml = (string)file_get_contents("$root/web/index.html");
$grpHtml = (string)file_get_contents("$root/web/ka4/index.html");
t('izolacija: korijen ne nabraja koncesije', !str_contains($rootHtml, '/ka4/') && !str_contains($rootHtml, '/kb1/') && !str_contains($rootHtml, 'Druga koncesija'));
t('izolacija: stranica lokacije nema poveznicu na korijen ni na druge koncesije', !str_contains($html, 'href="/"') && !str_contains($html, '/kb1/') && !str_contains($html, 'Druga koncesija'));
t('izolacija: stranica koncesije nema poveznicu na korijen ni druge koncesije', !str_contains($grpHtml, 'href="/"') && !str_contains($grpHtml, '/kb1/') && !str_contains($grpHtml, 'Druga koncesija'));
t('izolacija: nema sitemap.xml, robots ne nabraja koncesije', !is_file("$root/web/sitemap.xml") && !str_contains((string)file_get_contents("$root/web/robots.txt"), 'ka4'));
t('XSS u nazivu je escapean', !str_contains($html, '<b>GA</b>') && str_contains($html, '&lt;b&gt;GA&lt;/b&gt;'));

[$st, $b] = call($global['key_id'], $global['secret'], 'PUT', $u, PROIZVODI_CSV, [], true);
t('gzip upload prihvacen (ponovni upload istog dana)', $st === 200, json_encode($b));
t('jedan red u uploads po (lok,feed,dan)', (int)\Cjenik\Db::val('SELECT COUNT(*) FROM uploads') === 1);

[$st] = call($global['key_id'], $global['secret'], 'PUT', $u, PROIZVODI_CSV, ['HTTP_X_SIGNATURE' => str_repeat('0', 64)]);
t('kriv potpis -> 401', $st === 401);
[$st] = call($global['key_id'], 'kriva-tajna', 'PUT', $u, PROIZVODI_CSV);
t('kriva tajna -> 401', $st === 401);
[$st] = call('k_0000000000000000', $global['secret'], 'PUT', $u, PROIZVODI_CSV);
t('nepoznat key_id -> 401', $st === 401);
[$st] = call($global['key_id'], $global['secret'], 'PUT', $u, PROIZVODI_CSV, ['HTTP_X_TIMESTAMP' => (string)(Clock::now()->getTimestamp() - 1000)]);
t('star timestamp -> 401', $st === 401);

// replay: isti nonce dvaput s istim potpisom
$ts = (string)Clock::now()->getTimestamp();
$sha = hash('sha256', PROIZVODI_CSV);
$nonce = 'abcdef0123456789abcdef';
$sig = Hmac::sign($global['secret'], 'PUT', $u, $ts, $nonce, $sha);
$mk = static function () use ($u, $ts, $nonce, $sha, $sig, $global): array {
    $s = fopen('php://temp', 'w+');
    fwrite($s, PROIZVODI_CSV);
    rewind($s);
    return Api::process([
        'REMOTE_ADDR' => '203.0.113.5', 'HTTPS' => 'on', 'REQUEST_URI' => $u, 'HTTP_X_KEY_ID' => $global['key_id'], 'HTTP_X_TIMESTAMP' => $ts,
        'HTTP_X_NONCE' => $nonce, 'HTTP_X_CONTENT_SHA256' => $sha, 'HTTP_X_SIGNATURE' => $sig,
    ], 'PUT', $u, $s);
};
[$first] = $mk();
[$second] = $mk();
t('replay: prvi 200, drugi 401', $first === 200 && $second === 401, "$first/$second");

[$st] = call($global['key_id'], $global['secret'], 'PUT', $u . '?x=2', PROIZVODI_CSV, [], false, '203.0.113.5', $u . '?x=1');
t('potpis vezan uz URI (izmijenjen query -> 401)', $st === 401);

[$st] = call($global['key_id'], $global['secret'], 'PUT', $u, PROIZVODI_CSV, ['HTTP_X_CONTENT_SHA256' => hash('sha256', 'nesto drugo')]);
t('SHA tijela se mora podudarati s potpisanim', $st === 401 || $st === 400);

[$st] = call($locKey['key_id'], $locKey['secret'], 'PUT', '/api/v1/ka4/2/proizvodi', PROIZVODI_CSV);
t('kljuc lokacije ne pise u drugu lokaciju -> 403', $st === 403);
[$st] = call($grpKey['key_id'], $grpKey['secret'], 'PUT', '/api/v1/kb1/1/proizvodi', PROIZVODI_CSV);
t('kljuc koncesije ne pise u tudu koncesiju -> 403', $st === 403);
[$st] = call($grpKey['key_id'], $grpKey['secret'], 'PUT', '/api/v1/ka4/2/proizvodi', PROIZVODI_CSV);
t('kljuc koncesije pise u svoju lokaciju -> 200', $st === 200);
[$st, $b] = call($grpKey['key_id'], $grpKey['secret'], 'GET', '/api/v1/locations');
t('popis lokacija filtriran po opsegu kljuca', $st === 200 && count($b['locations']) === 2, json_encode($b));

[$st] = call($global['key_id'], $global['secret'], 'PUT', '/api/v1/ka4/2/radovi', USLUGE_CSV);
t('feed iskljucen za lokaciju -> 409', $st === 409);
[$st] = call($global['key_id'], $global['secret'], 'PUT', '/api/v1/zz9/1/radovi', USLUGE_CSV);
t('nepoznata lokacija -> 404', $st === 404);
[$st] = call($global['key_id'], $global['secret'], 'PUT', '/api/v1/ka4/1/../../etc/passwd', USLUGE_CSV);
t('path traversal u ruti -> 404', $st === 404);
[$st, $b] = call($global['key_id'], $global['secret'], 'PUT', '/api/v1/ka4/1/radovi', "naziv;x\n1;2\n");
t('los CSV -> 422 s popisom gresaka', $st === 422 && !empty($b['errors']), json_encode($b));
[$st] = call($global['key_id'], $global['secret'], 'PUT', $u . '?date=2026-11-10', PROIZVODI_CSV);
t('datum stariji od jucer -> 422', $st === 422);
[$st] = call($global['key_id'], $global['secret'], 'PUT', $u . '?date=2026-11-16', PROIZVODI_CSV);
t('jucerasnji datum -> 200', $st === 200);
t('jucerasnji upload ne dira latest.csv', file_get_contents("$root/web/ka4/1/proizvodi/latest.csv") === PROIZVODI_CSV && is_file(upFile($root, 'ka4', 1, 'proizvodi', '2026-11-16')));
[$st] = call($global['key_id'], $global['secret'], 'PUT', $u, '');
t('prazno tijelo -> 400', $st === 400);
[$st] = call($global['key_id'], $global['secret'], 'GET', '/api/v1/ping', '', [], false, '198.51.100.9');
t('bez allowliste dopusta svaku IP', $st === 200);

rrmdir($root);

// IP allowlist + throttle
$root = freshEnv();
Config::load([
    'app_key' => Crypto::generateKey(), 'webroot' => $root . '/web', 'storage' => $root . '/storage', 'timezone' => 'Europe/Zagreb',
    'require_https' => true, 'timestamp_window_seconds' => 300, 'retention_days' => 30, 'max_rows' => 1000, 'base_url' => 'https://x',
    'ingest_allowed_ips' => ['203.0.113.0/24'],
]);
$k = Keys::create('global', null, null, 'x');
[$st] = call($k['key_id'], $k['secret'], 'GET', '/api/v1/ping', '', [], false, '198.51.100.9');
t('IP izvan allowliste -> 403', $st === 403);
[$st] = call($k['key_id'], $k['secret'], 'GET', '/api/v1/ping', '', [], false, '203.0.113.50');
t('IP unutar allowliste -> 200', $st === 200);
for ($i = 0; $i < 30; $i++) {
    call($k['key_id'], 'kriva', 'GET', '/api/v1/ping', '', [], false, '203.0.113.77');
}
[$st] = call($k['key_id'], $k['secret'], 'GET', '/api/v1/ping', '', [], false, '203.0.113.77');
t('nakon 30 neuspjelih pokusaja IP je blokirana (429)', $st === 429);
[$st] = call($k['key_id'], $k['secret'], 'GET', '/api/v1/ping', '', ['HTTPS' => 'off'], false, '203.0.113.60');
t('bez HTTPS-a -> 400', $st === 400);
rrmdir($root);

// ---------------------------------------------------------------- Retencija 30 dana
echo "Retencija\n";
$root = freshEnv();
Locations::create(['grp' => 'ka4', 'betnr' => 1, 'naziv' => 'Split', 'feed_dijelovi' => 0]);
$loc = Locations::find('ka4', 1);
$csv = tmpCsv(USLUGE_CSV);
$base = new DateTimeImmutable('2026-11-17');
for ($i = 40; $i >= 0; $i--) {
    $d = $base->modify("-$i days")->format('Y-m-d');
    Clock::set(new DateTimeImmutable($d . ' 07:00:00', new DateTimeZone('Europe/Zagreb')));
    Publisher::publish($loc, 'radovi', $d, $csv, 1, 'agent', null, false);
}
$files = array_filter(glob("$root/web/ka4/1/radovi/*.csv") ?: [], static fn($p) => basename($p) !== 'latest.csv');
t('nakon 41 dana ostaje tocno 30 datoteka', count($files) === 30, (string)count($files));
t('najstarija je 30. dan unazad (2026-10-19)', Db::val('SELECT MIN(price_date) FROM uploads') === '2026-10-19' && is_file(upFile($root, 'ka4', 1, 'radovi', '2026-10-19')));
t('baza je u skladu s diskom', (int)\Cjenik\Db::val('SELECT COUNT(*) FROM uploads') === 30);
rrmdir($root);

// ---------------------------------------------------------------- Watchdog / prijenos jucerasnjeg
echo "Watchdog\n";
$root = freshEnv();
Locations::create(['grp' => 'ka4', 'betnr' => 1, 'naziv' => 'Split', 'feed_vozila' => 0]);
Locations::create(['grp' => 'ka4', 'betnr' => 2, 'naziv' => 'Sibenik', 'feed_vozila' => 0, 'feed_radovi' => 0]);
$k = Keys::create('global', null, null, 'agent');
Clock::set(new DateTimeImmutable('2026-11-16 07:00:00', new DateTimeZone('Europe/Zagreb')));
call($k['key_id'], $k['secret'], 'PUT', '/api/v1/ka4/1/proizvodi', PROIZVODI_CSV);
call($k['key_id'], $k['secret'], 'PUT', '/api/v1/ka4/1/radovi', USLUGE_CSV);
call($k['key_id'], $k['secret'], 'PUT', '/api/v1/ka4/2/proizvodi', PROIZVODI_CSV);

Clock::set(new DateTimeImmutable('2026-11-17 07:30:00', new DateTimeZone('Europe/Zagreb')));
$s = Maintenance::run();
t('07:30: nedostaje 3 feeda, nista se ne prenosi', count($s['missing']) === 3 && $s['carried'] === []);
Clock::set(new DateTimeImmutable('2026-11-17 07:42:00', new DateTimeZone('Europe/Zagreb')));
Maintenance::run();
t('07:42: alert je zabiljezen jednom', \Cjenik\Support::getState('alert_sent:2026-11-17') !== null);
// jedan feed stigne na vrijeme
call($k['key_id'], $k['secret'], 'PUT', '/api/v1/ka4/2/proizvodi', PROIZVODI_CSV);
Clock::set(new DateTimeImmutable('2026-11-17 07:56:00', new DateTimeZone('Europe/Zagreb')));
$s = Maintenance::run();
t('07:56: preneseno 2 (ka4/1 dijelovi+radovi), ka4/2 stigao sam', count($s['carried']) === 2 && count($s['missing']) === 2, json_encode($s));
$src = Db::one('SELECT source FROM uploads WHERE location_id = ? AND feed = ? AND price_date = ?', [Locations::find('ka4', 1)['id'], 'radovi', '2026-11-17']);
t('preneseni zapis ima source=carryover', ($src['source'] ?? '') === 'carryover');
t('latest.csv postoji nakon prijenosa', is_file("$root/web/ka4/1/radovi/latest.csv"));
$s = Maintenance::run();
t('drugi prolaz nema nista za prenijeti', $s['missing'] === [] && $s['carried'] === []);
t('stranica javlja da je cjenik prenesen', str_contains((string)file_get_contents("$root/web/ka4/1/index.html"), 'preuzet cjenik iz prethodnog dana'));
rrmdir($root);

// ---------------------------------------------------------------- iskljucivanje cjenika (set-feeds)
echo "Iskljucivanje cjenika\n";
$root = freshEnv();
Locations::create(['grp' => 'ka4', 'betnr' => 101, 'naziv' => 'Split']);
Locations::create(['grp' => 'ka4', 'betnr' => 102, 'naziv' => 'Sibenik']);
$id101 = (int)Locations::find('ka4', 101)['id'];
t('prije: sva tri izvora ukljucena, objavljuju se dva cjenika', Locations::sources(Locations::find('ka4', 101)) === ['dijelovi', 'vozila', 'radovi'] && Locations::feeds(Locations::find('ka4', 101)) === ['proizvodi', 'radovi']);
t('setFeed vozila off mijenja', Locations::setFeed($id101, 'vozila', false) === true);
t('nakon: izvor vozila iskljucen, cjenik proizvodi ostaje (ima dijelove)', Locations::sources(Locations::find('ka4', 101)) === ['dijelovi', 'radovi'] && Locations::feeds(Locations::find('ka4', 101)) === ['proizvodi', 'radovi']);
t('susjedna lokacija nije dirnuta', Locations::sources(Locations::find('ka4', 102)) === ['dijelovi', 'vozila', 'radovi']);
t('ponovno isto stanje: nema promjene', Locations::setFeed($id101, 'vozila', false) === false);
t('setFeed vozila on vraca izvor', Locations::setFeed($id101, 'vozila', true) === true && in_array('vozila', Locations::sources(Locations::find('ka4', 101)), true));
Locations::setFeed($id101, 'vozila', false);
Locations::setFeed($id101, 'dijelovi', false);
t('bez dijelova i vozila nema cjenika proizvodi, radovi ostaju', Locations::feeds(Locations::find('ka4', 101)) === ['radovi']);
Locations::setFeed($id101, 'dijelovi', true);
Locations::setFeed($id101, 'vozila', true);
$bad = false;
try {
    Locations::setFeed($id101, 'vozila; DROP TABLE locations', false);
} catch (InvalidArgumentException) {
    $bad = true;
}
t('nepoznat cjenik odbijen (nema SQL injectiona)', $bad);
$bad = false;
try {
    Locations::setFeed(999999, 'vozila', false);
} catch (InvalidArgumentException) {
    $bad = true;
}
t('nepoznata lokacija odbijena', $bad);
Locations::setFeed($id101, 'vozila', false);
$k = Keys::create('global', null, null, 'agent');
$r = call($k['key_id'], $k['secret'], 'GET', '/api/v1/locations', '');
t('API javlja cjenike i ukljucene izvore (vozila iskljucena)', str_contains(json_encode($r), '"betnr":101,"naziv":"Split","feeds":["proizvodi","radovi"],"sources":["dijelovi","radovi"]'), json_encode($r));
rrmdir($root);

// ---------------------------------------------------------------- jednokratno ciscenje starih cjenika dijelovi/vozila
echo "Ciscenje starih cjenika\n";
$root = freshEnv();
Locations::create(['grp' => 'ka4', 'betnr' => 1, 'naziv' => 'Split']);
$lid = (int)Locations::find('ka4', 1)['id'];
foreach (['dijelovi', 'vozila', 'radovi'] as $old) {
    @mkdir("$root/web/ka4/1/$old", 0777, true);
    file_put_contents("$root/web/ka4/1/$old/latest.csv", "x\n");
    Db::run('INSERT INTO uploads (location_id, feed, price_date, rows_count, bytes, sha256, source, created_at) VALUES (?,?,?,?,?,?,?,?)', [$lid, $old, '2026-11-16', 1, 2, str_repeat('a', 64), 'agent', '2026-11-16 06:00:00']);
}
$p = Locations::purgeLegacyFeeds();
t('stari cjenici dijelovi i vozila obrisani (zapisi i mape)', $p['done'] === true && $p['uploads'] === 2 && !is_dir("$root/web/ka4/1/dijelovi") && !is_dir("$root/web/ka4/1/vozila"));
t('radovi nisu dirnuti', is_file("$root/web/ka4/1/radovi/latest.csv") && (int)Db::val("SELECT COUNT(*) FROM uploads WHERE feed = 'radovi'") === 1);
t('ciscenje je jednokratno', Locations::purgeLegacyFeeds()['done'] === false);
rrmdir($root);

// ---------------------------------------------------------------- slug (javna adresa po nazivu)
echo "Slug\n";
$root = freshEnv();
Locations::create(['grp' => 'ka7', 'betnr' => 1, 'naziv' => 'Roca']);
Locations::create(['grp' => 'ka4', 'betnr' => 1, 'naziv' => 'Split']);
$k = Keys::create('global', null, null, 'agent');
call($k['key_id'], $k['secret'], 'PUT', '/api/v1/ka7/1/radovi', USLUGE_CSV);
t('prije sluga CSV je pod GRP-om', is_file("$root/web/ka7/1/radovi/latest.csv"));
Locations::setSlug('ka7', 'autocentarroca');
Pages::renderAll();
t('slug: mapa premjestena, stara ne postoji', is_file("$root/web/autocentarroca/1/radovi/latest.csv") && !is_dir("$root/web/ka7"));
t('slug: stranice pod novom putanjom', is_file("$root/web/autocentarroca/index.html") && is_file("$root/web/autocentarroca/1/index.html") && is_file("$root/web/autocentarroca/format.html"));
$h = (string)file_get_contents("$root/web/autocentarroca/1/index.html");
t('slug: poveznice na stranici koriste slug', str_contains($h, '/autocentarroca/1/radovi/latest.csv') && !str_contains($h, '/ka7/'));
[$st, $b] = call($k['key_id'], $k['secret'], 'PUT', '/api/v1/ka7/1/radovi', USLUGE_CSV);
t('API i dalje radi s GRP-om, a datoteka ide pod slug', $st === 200 && is_file(upFile($root, 'ka7', 1, 'radovi', '2026-11-17')) && !is_dir("$root/web/ka7"), json_encode($b));
$threw = static function (callable $f): bool {
    try {
        $f();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
};
t('slug: neispravni se odbijaju (velika slova, razmak, ..)', $threw(fn() => Locations::setSlug('ka4', 'Auto Centar')) && $threw(fn() => Locations::setSlug('ka4', '../x')) && $threw(fn() => Locations::setSlug('ka4', 'a')));
t('slug: rezervirani (admin, api) se odbijaju', $threw(fn() => Locations::setSlug('ka4', 'admin')) && $threw(fn() => Locations::setSlug('ka4', 'api')));
t('slug: duplikat tudjeg sluga se odbija', $threw(fn() => Locations::setSlug('ka4', 'autocentarroca')));
Locations::create(['grp' => 'kb1', 'betnr' => 1, 'naziv' => 'x']);
t('slug: tudji GRP kao slug se odbija', $threw(fn() => Locations::setSlug('ka4', 'kb1')));
Locations::setSlug('kb1', 'roca7');
t('novi GRP jednak tudjem slugu se odbija', $threw(fn() => Locations::create(['grp' => 'roca7', 'betnr' => 1, 'naziv' => 'x'])));
Locations::setSlug('kb1', '');
Locations::setSlug('ka7', '');
t('slug: prazan vraca na GRP i premjesta mapu natrag', is_file("$root/web/ka7/1/radovi/latest.csv") && !is_dir("$root/web/autocentarroca"));
t('slugify: auto centar roca -> autocentarroca', \Cjenik\Support::slugify('auto centar roca') === 'autocentarroca');
t('slugify: dijakritici, tocke, crtica', \Cjenik\Support::slugify('autodom-vidaković slav. brod') === 'autodom-vidakovicslavbrod' && \Cjenik\Support::slugify('rb - auto') === 'rb-auto' && \Cjenik\Support::slugify('d.r. auto') === 'drauto');
// brisanje lokacije u koncesiji sa slugom
Locations::setSlug('ka7', 'autocentarroca');
Pages::renderAll();
$r = Locations::delete((int)Locations::find('ka7', 1)['id']);
t('delete radi i kad koncesija ima slug', !is_dir("$root/web/autocentarroca") && $r['concession_removed'] === true);
rrmdir($root);

// ---------------------------------------------------------------- dva cjenika: proizvodi (vozila i dijelovi zajedno) i radovi
echo "Feedovi\n";
$root = freshEnv();
Locations::create(['grp' => 'kb5', 'betnr' => 21, 'naziv' => 'RB AUTO ZAGREB']);
$k = Keys::create('global', null, null, 'agent');
t('lokacija objavljuje dva cjenika', Locations::feeds(Locations::find('kb5', 21)) === ['proizvodi', 'radovi']);
[$s1] = call($k['key_id'], $k['secret'], 'PUT', '/api/v1/kb5/21/proizvodi', PROIZVODI_CSV . "Mazda CX-5;123456;Mazda;1;KOM;30000.00;30000.00;ne;;30000.00;30000.00;;dostupno;30000.00\n");
[$s3] = call($k['key_id'], $k['secret'], 'PUT', '/api/v1/kb5/21/radovi', USLUGE_CSV);
t('oba cjenika se primaju', $s1 === 200 && $s3 === 200, "$s1/$s3");
t('vozila i dijelovi su u istoj datoteci', str_contains((string)file_get_contents("$root/web/kb5/21/proizvodi/latest.csv"), 'Mazda CX-5') && str_contains((string)file_get_contents("$root/web/kb5/21/proizvodi/latest.csv"), 'Filter ulja') && is_file("$root/web/kb5/21/radovi/latest.csv"));
[$s4] = call($k['key_id'], $k['secret'], 'PUT', '/api/v1/kb5/21/dijelovi', PROIZVODI_CSV);
[$s5] = call($k['key_id'], $k['secret'], 'PUT', '/api/v1/kb5/21/vozila', PROIZVODI_CSV);
[$s5b] = call($k['key_id'], $k['secret'], 'PUT', '/api/v1/kb5/21/usluge', USLUGE_CSV);
t('stari odvojeni cjenici dijelovi/vozila/usluge vise ne postoje (404)', $s4 === 404 && $s5 === 404 && $s5b === 404, "$s4/$s5/$s5b");
[$s6, $b6] = call($k['key_id'], $k['secret'], 'PUT', '/api/v1/kb5/21/radovi', PROIZVODI_CSV);
t('radovi odbija stupce proizvoda (422), proizvodi odbija stupce usluga', $s6 === 422 && call($k['key_id'], $k['secret'], 'PUT', '/api/v1/kb5/21/proizvodi', USLUGE_CSV)[0] === 422);
$h = (string)file_get_contents("$root/web/kb5/21/index.html");
t('stranica lokacije ima dva odjeljka: Vozila i proizvodi, Radovi', str_contains($h, 'Vozila i proizvodi') && str_contains($h, 'Radovi (usluge)') && !str_contains($h, '>Rezervni dijelovi<'));
Locations::update((int)Locations::find('kb5', 21)['id'], ['naziv' => 'RB AUTO ZAGREB', 'feed_dijelovi' => 0, 'feed_vozila' => 0, 'feed_radovi' => 1, 'active' => 1]);
t('bez dijelova i vozila nema cjenika proizvodi', Locations::feeds(Locations::find('kb5', 21)) === ['radovi']);
[$s7] = call($k['key_id'], $k['secret'], 'PUT', '/api/v1/kb5/21/proizvodi', PROIZVODI_CSV);
t('iskljuceni cjenik se ne prima (409)', $s7 === 409);
$locsJson = call($k['key_id'], $k['secret'], 'GET', '/api/v1/locations', '');
t('API vise ne nudi cjenik proizvodi te lokacije', str_contains(json_encode($locsJson), '"betnr":21,"naziv":"RB AUTO ZAGREB","feeds":["radovi"],"sources":["radovi"]'), json_encode($locsJson));
Locations::update((int)Locations::find('kb5', 21)['id'], ['naziv' => 'RB AUTO ZAGREB', 'feed_dijelovi' => 1, 'feed_vozila' => 0, 'feed_radovi' => 1, 'active' => 1]);
t('samo dijelovi ukljuceni: cjenik proizvodi postoji', Locations::feeds(Locations::find('kb5', 21)) === ['proizvodi', 'radovi'] && Locations::sources(Locations::find('kb5', 21)) === ['dijelovi', 'radovi']);
// naziv datoteke prema nacrtu Pravilnika (cl. 6(2)), broj pohrane, zamjena istog dana, neto kolicina
$root2 = $root;
Locations::update((int)Locations::find('kb5', 21)['id'], ['naziv' => 'RB AUTO ZAGREB', 'adresa' => 'Ulica 1, Zagreb', 'oblik_mjesta' => 'Salon', 'feed_dijelovi' => 1, 'feed_vozila' => 1, 'feed_radovi' => 1, 'active' => 1]);
Clock::set(new DateTimeImmutable('2026-11-17 07:00:00', new DateTimeZone('Europe/Zagreb')));
call($k['key_id'], $k['secret'], 'PUT', '/api/v1/kb5/21/proizvodi', PROIZVODI_CSV);
$n1 = basename(upFile($root2, 'kb5', 21, 'proizvodi', '2026-11-17'));
t('naziv datoteke: objekt_oblik_adresa_oznaka_feed_broj_vrijeme', $n1 === 'rb-auto-zagreb_salon_ulica-1-zagreb_kb521_proizvodi_000003_20261117070000.csv' || preg_match('/^rb-auto-zagreb_salon_ulica-1-zagreb_kb521_proizvodi_\d{6}_20261117070000\.csv$/', $n1) === 1, $n1);
$seq1 = (int)substr($n1, -25, 6);
Clock::set(new DateTimeImmutable('2026-11-17 07:30:00', new DateTimeZone('Europe/Zagreb')));
call($k['key_id'], $k['secret'], 'PUT', '/api/v1/kb5/21/proizvodi', PROIZVODI_CSV);
$n2 = basename(upFile($root2, 'kb5', 21, 'proizvodi', '2026-11-17'));
t('broj pohrane raste pri novoj objavi, vrijeme slanja se mijenja', $n2 !== $n1 && (int)substr($n2, -25, 6) === $seq1 + 1 && str_ends_with($n2, '_20261117073000.csv'), "$n1 / $n2");
t('ponovni upload istog dana zamjenjuje staru datoteku (nema duplikata)', !is_file("$root2/web/kb5/21/proizvodi/$n1") && is_file("$root2/web/kb5/21/proizvodi/$n2"));
$h2 = (string)file_get_contents("$root2/web/kb5/21/index.html");
t('stranica linka na datoteku s propisanim nazivom (ne na datum.csv)', str_contains($h2, $n2) && !str_contains($h2, '2026-11-17.csv'));
t('neto_kolicina: valjan broj prolazi', CsvSpec::validate(tmpCsv(str_replace('Filter ulja;F-100;Mazda;;kom;;', 'Filter ulja;F-100;Mazda;0.75;l;;', PROIZVODI_CSV)), 'proizvodi')['ok']);
t('neto_kolicina: zarez i tekst se odbijaju', !CsvSpec::validate(tmpCsv(str_replace('Filter ulja;F-100;Mazda;;kom;;', 'Filter ulja;F-100;Mazda;0,75;l;;', PROIZVODI_CSV)), 'proizvodi')['ok'] && !CsvSpec::validate(tmpCsv(str_replace('Filter ulja;F-100;Mazda;;kom;;', 'Filter ulja;F-100;Mazda;5 l;l;;', PROIZVODI_CSV)), 'proizvodi')['ok']);
t('zaglavlje bez bazna_cijena / najniza_cijena_30_dana se odbija', !CsvSpec::validate(tmpCsv("naziv_usluge;maloprodajna_cijena;posebni_oblik_prodaje;naziv_posebnog_oblika_prodaje;sidrena_cijena\nX;1.00;ne;;1.00\n"), 'radovi')['ok']);
t('bazna_cijena je obavezna', !CsvSpec::validate(tmpCsv(str_replace('55.00;55.00;55.00', ';55.00;55.00', USLUGE_CSV)), 'radovi')['ok']);
t('najniza_cijena_30_dana je obavezna', !CsvSpec::validate(tmpCsv(str_replace('55.00;55.00;55.00', '55.00;55.00;', USLUGE_CSV)), 'radovi')['ok']);
t('staro zaglavlje bez neto_kolicina se odbija', !CsvSpec::validate(tmpCsv("naziv;sifra;marka;jedinica_mjere;cijena_po_jedinici_mjere;maloprodajna_cijena;posebni_oblik_prodaje;naziv_posebnog_oblika_prodaje;sidrena_cijena;barkod;dostupnost\nX;1;M;kom;;1.00;ne;;1.00;;dostupno\n"), 'proizvodi')['ok']);
rrmdir($root);

// ---------------------------------------------------------------- brisanje lokacije
echo "Brisanje\n";
$root = freshEnv();
Locations::create(['grp' => 'ki2', 'betnr' => 1, 'naziv' => 'x']);
Locations::create(['grp' => 'ka4', 'betnr' => 1, 'naziv' => 'y']);
Locations::create(['grp' => 'ka4', 'betnr' => 2, 'naziv' => 'z']);
$k = Keys::create('global', null, null, 'agent');
call($k['key_id'], $k['secret'], 'PUT', '/api/v1/ki2/1/radovi', USLUGE_CSV);
call($k['key_id'], $k['secret'], 'PUT', '/api/v1/ka4/1/radovi', USLUGE_CSV);
t('prije brisanja ki2 postoji', is_file("$root/web/ki2/1/radovi/latest.csv") && is_dir("$root/web/ki2"));
$r = Locations::delete((int)Locations::find('ki2', 1)['id']);
t('delete: datoteke obrisane, prazna koncesija obrisana', !is_dir("$root/web/ki2") && $r['concession_removed'] === true && $r['files'] >= 3, json_encode($r));
t('delete: baza ociscena', Locations::find('ki2', 1) === null && (int)Db::val('SELECT COUNT(*) FROM concessions WHERE grp = ?', ['ki2']) === 0 && (int)Db::val('SELECT COUNT(*) FROM uploads WHERE location_id NOT IN (SELECT id FROM locations)') === 0);
$r = Locations::delete((int)Locations::find('ka4', 2)['id']);
t('delete: koncesija s drugom lokacijom ostaje', $r['concession_removed'] === false && is_file("$root/web/ka4/1/radovi/latest.csv"));
Pages::renderAll();
t('nakon brisanja stranica ki2 ne postoji, ka4 ostaje', !is_file("$root/web/ki2/index.html") && is_file("$root/web/ka4/1/index.html"));
rrmdir($root);

// ---------------------------------------------------------------- sazetak
echo "\n$pass proslo, $fail palo\n";
exit($fail === 0 ? 0 : 1);
