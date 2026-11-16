<?php
declare(strict_types=1);

namespace Cjenik;

use DateTimeImmutable;
use PDOException;
use Throwable;

/**
 * Ingest API (jedini put za podatke osim rucnog uploada u adminu).
 *   GET /api/v1/ping
 *   GET /api/v1/locations
 *   PUT /api/v1/{grp}/{betnr}/{proizvodi|radovi}[?date=YYYY-MM-DD]   (tijelo: CSV, opcionalno gzip uz Content-Encoding: gzip)
 */
final class Api
{
    public static function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $in = fopen('php://input', 'rb');
        [$status, $body] = self::process($_SERVER, (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string)($_SERVER['REQUEST_URI'] ?? '/'), $in);
        if (is_resource($in)) {
            fclose($in);
        }
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string,mixed> $server
     * @param resource|null $bodyStream
     * @return array{0:int, 1:array<string,mixed>}
     */
    public static function process(array $server, string $method, string $uri, $bodyStream): array
    {
        $tmpFiles = [];
        try {
            $ip = Ip::client($server);
            if (!Ip::allowed($ip, (array)Config::get('ingest_allowed_ips', []))) {
                throw new ApiError(403, 'Zabranjeno.');
            }
            if (Config::get('require_https', true) && !self::isHttps($server)) {
                throw new ApiError(400, 'Potreban je HTTPS.');
            }
            if (Support::isBlocked('ingest', $ip, 30, 900)) {
                throw new ApiError(429, 'Previse neuspjelih pokusaja. Pokusajte kasnije.');
            }

            $path = (string)parse_url($uri, PHP_URL_PATH);
            $route = self::route($method, $path);
            if ($route === null) {
                throw new ApiError(404, 'Nepoznata ruta.');
            }

            try {
                $key = self::authenticate($server, $method, $uri);
            } catch (ApiError $e) {
                Support::recordFailure('ingest', $ip);
                throw $e;
            }

            return match ($route['name']) {
                'ping' => [200, ['ok' => true, 'time' => Clock::now()->format(DATE_ATOM), 'today' => Clock::today()]],
                'locations' => [200, self::locations($key)],
                default => self::put($key, $route, $server, $bodyStream, $tmpFiles),
            };
        } catch (ApiError $e) {
            return [$e->status, ['ok' => false, 'error' => $e->getMessage()] + $e->extra];
        } catch (Throwable $t) {
            error_log('cjenik api: ' . $t::class . ': ' . $t->getMessage());
            return [500, ['ok' => false, 'error' => 'Interna greska.']];
        } finally {
            foreach ($tmpFiles as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }
    }

    /** @param array<string,mixed> $server */
    private static function isHttps(array $server): bool
    {
        if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') {
            return true;
        }
        $hdr = Config::get('client_ip_header') ? ($server['HTTP_X_FORWARDED_PROTO'] ?? '') : '';
        return $hdr === 'https';
    }

    /** @return array{name:string, grp?:string, betnr?:int, feed?:string}|null */
    private static function route(string $method, string $path): ?array
    {
        if ($method === 'GET' && $path === '/api/v1/ping') {
            return ['name' => 'ping'];
        }
        if ($method === 'GET' && $path === '/api/v1/locations') {
            return ['name' => 'locations'];
        }
        if ($method === 'PUT' && preg_match('#^/api/v1/([a-z0-9]{2,8})/(\d{1,5})/(proizvodi|radovi)$#', $path, $m) === 1) {
            return ['name' => 'put', 'grp' => $m[1], 'betnr' => (int)$m[2], 'feed' => $m[3]];
        }
        return null;
    }

    /**
     * @param array<string,mixed> $server
     * @return array<string,mixed> red iz api_keys
     */
    private static function authenticate(array $server, string $method, string $uri): array
    {
        $keyId = (string)($server['HTTP_X_KEY_ID'] ?? '');
        $ts = (string)($server['HTTP_X_TIMESTAMP'] ?? '');
        $nonce = (string)($server['HTTP_X_NONCE'] ?? '');
        $sha = strtolower((string)($server['HTTP_X_CONTENT_SHA256'] ?? ''));
        $sig = strtolower((string)($server['HTTP_X_SIGNATURE'] ?? ''));
        if (
            preg_match('/^k_[0-9a-f]{16}$/', $keyId) !== 1 || preg_match('/^\d{9,12}$/', $ts) !== 1
            || preg_match('/^[A-Za-z0-9]{16,64}$/', $nonce) !== 1 || preg_match('/^[0-9a-f]{64}$/', $sha) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $sig) !== 1
        ) {
            throw new ApiError(401, 'Neispravna autentikacija.');
        }
        $now = Clock::now()->getTimestamp();
        $window = (int)Config::get('timestamp_window_seconds', 300);
        if (abs($now - (int)$ts) > $window) {
            throw new ApiError(401, 'Vremenska oznaka izvan dopustenog prozora (provjeri sat na agentu).', ['server_time' => $now]);
        }
        $key = Db::one('SELECT * FROM api_keys WHERE key_id = ?', [$keyId]);
        if ($key === null || $key['revoked_at'] !== null) {
            throw new ApiError(401, 'Neispravna autentikacija.');
        }
        $secret = Crypto::decrypt((string)$key['secret_enc']);
        $expected = Hmac::sign($secret, $method, $uri, $ts, $nonce, $sha);
        if (!hash_equals($expected, $sig)) {
            throw new ApiError(401, 'Neispravna autentikacija.');
        }
        Db::run('DELETE FROM nonces WHERE ts < ?', [$now - 2 * $window]);
        try {
            Db::run('INSERT INTO nonces (key_id, nonce, ts) VALUES (?,?,?)', [$keyId, $nonce, $now]);
        } catch (PDOException) {
            throw new ApiError(401, 'Zahtjev je vec iskoristen (replay).');
        }
        Db::run('UPDATE api_keys SET last_used_at = ? WHERE id = ?', [Clock::utcStamp(), $key['id']]);
        return $key;
    }

    /** @param array<string,mixed> $key @return array<string,mixed> */
    private static function locations(array $key): array
    {
        $out = [];
        foreach (Locations::all(true) as $l) {
            if (Keys::allows($key, $l)) {
                $out[] = ['grp' => $l['grp'], 'betnr' => (int)$l['betnr'], 'naziv' => $l['naziv'], 'feeds' => Locations::feeds($l), 'sources' => Locations::sources($l)];
            }
        }
        return ['ok' => true, 'locations' => $out];
    }

    /**
     * @param array<string,mixed> $key
     * @param array{name:string, grp?:string, betnr?:int, feed?:string} $route
     * @param array<string,mixed> $server
     * @param resource|null $in
     * @param list<string> $tmpFiles
     * @return array{0:int, 1:array<string,mixed>}
     */
    private static function put(array $key, array $route, array $server, $in, array &$tmpFiles): array
    {
        $loc = Locations::find((string)$route['grp'], (int)$route['betnr']);
        if ($loc === null || (int)$loc['active'] !== 1) {
            throw new ApiError(404, 'Lokacija ne postoji.');
        }
        if (!Keys::allows($key, $loc)) {
            throw new ApiError(403, 'Kljuc nema pravo na ovu lokaciju.');
        }
        $feed = (string)$route['feed'];
        if (!in_array($feed, Locations::feeds($loc), true)) {
            throw new ApiError(409, 'Feed nije ukljucen za ovu lokaciju.');
        }

        $uriQuery = (string)parse_url((string)($server['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
        parse_str($uriQuery, $q);
        $today = Clock::today();
        $date = isset($q['date']) ? (string)$q['date'] : $today;
        $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        if ($date !== $today && $date !== $yesterday) {
            throw new ApiError(422, 'Datum mora biti danas ili jucer.');
        }

        // tijelo -> privremena datoteka uz racunanje SHA-256 i ogranicenje velicine
        $dir = self::tmpDir();
        $raw = tempnam($dir, 'in');
        $tmpFiles[] = $raw;
        $max = (int)Config::get('max_body_bytes', 100 * 1024 * 1024);
        $out = fopen($raw, 'wb');
        $ctx = hash_init('sha256');
        $total = 0;
        if (is_resource($in)) {
            while (!feof($in)) {
                $chunk = fread($in, 1 << 16);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $total += strlen($chunk);
                if ($total > $max) {
                    fclose($out);
                    throw new ApiError(413, 'Tijelo zahtjeva je preveliko.');
                }
                hash_update($ctx, $chunk);
                fwrite($out, $chunk);
            }
        }
        fclose($out);
        if ($total === 0) {
            throw new ApiError(400, 'Prazno tijelo.');
        }
        if (!hash_equals(hash_final($ctx), strtolower((string)($server['HTTP_X_CONTENT_SHA256'] ?? '')))) {
            throw new ApiError(400, 'SHA-256 tijela ne odgovara zaglavlju.');
        }

        $csv = $raw;
        if (strtolower((string)($server['HTTP_CONTENT_ENCODING'] ?? '')) === 'gzip') {
            $csv = tempnam($dir, 'csv');
            $tmpFiles[] = $csv;
            self::gunzip($raw, $csv, (int)Config::get('max_decompressed_bytes', 1024 * 1024 * 1024));
        }

        $res = CsvSpec::validate($csv, $feed, (int)Config::get('max_rows', 2000000));
        if (!$res['ok']) {
            Support::audit('key:' . $key['key_id'], 'ingest.rejected', $loc['grp'] . '/' . $loc['betnr'] . '/' . $feed . ': ' . ($res['errors'][0] ?? ''));
            throw new ApiError(422, 'CSV nije prosao validaciju.', ['errors' => $res['errors']]);
        }

        $pub = Publisher::publish($loc, $feed, $date, $csv, $res['rows'], 'agent', (string)$key['key_id']);
        return [200, [
            'ok' => true, 'location' => $loc['grp'] . '/' . $loc['betnr'], 'feed' => $feed, 'date' => $date,
            'rows' => $res['rows'], 'bytes' => $pub['bytes'], 'sha256' => $pub['sha256'],
        ]];
    }

    private static function tmpDir(): string
    {
        $base = (string)Config::get('storage', sys_get_temp_dir());
        $dir = $base . '/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return is_dir($dir) ? $dir : sys_get_temp_dir();
    }

    private static function gunzip(string $src, string $dst, int $maxBytes): void
    {
        $in = @gzopen($src, 'rb');
        if ($in === false) {
            throw new ApiError(400, 'Neispravan gzip.');
        }
        $out = fopen($dst, 'wb');
        $total = 0;
        while (!gzeof($in)) {
            $chunk = gzread($in, 1 << 16);
            if ($chunk === false) {
                gzclose($in);
                fclose($out);
                throw new ApiError(400, 'Neispravan gzip.');
            }
            $total += strlen($chunk);
            if ($total > $maxBytes) {
                gzclose($in);
                fclose($out);
                throw new ApiError(413, 'Dekomprimirano tijelo je preveliko.');
            }
            fwrite($out, $chunk);
        }
        gzclose($in);
        fclose($out);
        if ($total === 0) {
            throw new ApiError(400, 'Prazan gzip.');
        }
    }
}
