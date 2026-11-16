<?php
declare(strict_types=1);

namespace Cjenik;

use InvalidArgumentException;
use Throwable;

/**
 * Admin panel. Zasticen: IP allowlist -> lozinka (+throttle) -> TOTP 2FA (obavezan) -> sesija
 * (Secure/HttpOnly/SameSite=Strict, idle 30 min), CSRF na svim POST-ovima, audit log.
 */
final class Admin
{
    private const IDLE = 1800;
    private const MAX_AGE = 43200;

    /** @var array<string,mixed> */
    private static array $user = [];

    public static function handle(string $path): void
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('Content-Type: text/html; charset=utf-8');

        $ip = Ip::client();
        if (!Ip::allowed($ip, (array)Config::get('admin_allowed_ips', []))) {
            http_response_code(403);
            echo 'Zabranjeno.';
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (Config::get('client_ip_header') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if (Config::get('require_https', true) && !$https) {
            http_response_code(400);
            echo 'Potreban je HTTPS.';
            return;
        }
        self::startSession($https);

        $route = trim(substr($path, strlen('/admin')), '/');
        $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($route === 'login') {
            self::login($method, $ip);
            return;
        }
        if ($route === 'totp') {
            self::totp($method, $ip);
            return;
        }
        if ($route === 'logout') {
            if ($method === 'POST') {
                self::checkCsrf();
                self::destroySession();
            }
            self::redirect('/admin/login');
        }

        self::requireUser();
        if ($method === 'POST') {
            self::checkCsrf();
        }

        try {
            switch (true) {
                case $route === '':
                    self::dashboard();
                    return;
                case $route === 'locations':
                    self::locations($method);
                    return;
                case $route === 'locations/new':
                    self::locationForm(null, $method);
                    return;
                case preg_match('#^locations/(\d+)$#', $route, $m) === 1:
                    self::locationForm((int)$m[1], $method);
                    return;
                case $route === 'concessions':
                    self::concessions($method);
                    return;
                case $route === 'keys':
                    self::keys($method);
                    return;
                case $route === 'upload':
                    self::upload($method);
                    return;
                case $route === 'import':
                    self::import($method);
                    return;
                case $route === 'audit':
                    self::page('admin/audit', ['rows' => Db::all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 300')]);
                    return;
                case $route === 'password':
                    self::password($method);
                    return;
                case $route === 'rebuild' && $method === 'POST':
                    Pages::renderAll();
                    Support::audit(self::$user['username'], 'pages.rebuild');
                    self::flash('ok', 'Javne stranice su ponovno generirane.');
                    self::redirect('/admin/');
            }
        } catch (InvalidArgumentException $e) {
            self::flash('err', $e->getMessage());
            self::redirect($_SERVER['REQUEST_URI'] ?? '/admin/');
        }
        http_response_code(404);
        self::page('admin/notfound', []);
    }

    // ---------------------------------------------------------------- sesija / auth

    private static function startSession(bool $https): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('cjenik_admin');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
        $now = time();
        if (isset($_SESSION['uid'])) {
            if ($now - (int)($_SESSION['last'] ?? 0) > self::IDLE || $now - (int)($_SESSION['start'] ?? 0) > self::MAX_AGE) {
                self::destroySession();
                session_start();
            }
        }
        $_SESSION['last'] = $now;
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    private static function destroySession(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private static function requireUser(): void
    {
        $uid = (int)($_SESSION['uid'] ?? 0);
        $u = $uid > 0 ? Db::one('SELECT * FROM admins WHERE id = ? AND disabled = 0', [$uid]) : null;
        if ($u === null) {
            self::redirect('/admin/login');
        }
        self::$user = $u;
    }

    private static function checkCsrf(): void
    {
        $t = (string)($_POST['_csrf'] ?? '');
        if (!hash_equals((string)($_SESSION['csrf'] ?? ''), $t)) {
            http_response_code(403);
            echo 'Neispravan CSRF token. Vrati se i pokusaj ponovno.';
            exit;
        }
    }

    private static function redirect(string $to): never
    {
        header('Location: ' . $to, true, 303);
        exit;
    }

    private static function flash(string $type, string $msg): void
    {
        $_SESSION['flash'][] = [$type, $msg];
    }

    // ---------------------------------------------------------------- login + 2FA

    private static function login(string $method, string $ip): void
    {
        $error = null;
        if ($method === 'POST') {
            self::checkCsrf();
            if (Support::isBlocked('admin', $ip, 8, 900)) {
                http_response_code(429);
                self::page('admin/login', ['error' => 'Previse neuspjelih pokusaja. Pokusaj kasnije.'], false);
                return;
            }
            $username = trim((string)($_POST['username'] ?? ''));
            $u = Db::one('SELECT * FROM admins WHERE username = ? AND disabled = 0', [$username]);
            // isti trosak bez obzira postoji li korisnik (izbjegava enumeraciju)
            $hash = $u['pass_hash'] ?? '$2y$12$AVsUzH8MejKHuCIsUeGWoO8VhCm2TTinvC6Zi.YtvqkCATlgf9EJ2'; // valjan hash nasumicne lozinke
            $ok = password_verify((string)($_POST['password'] ?? ''), $hash) && $u !== null;
            if ($ok) {
                Support::clearFailures('admin', $ip);
                session_regenerate_id(true);
                $_SESSION['pending_uid'] = (int)$u['id'];
                $_SESSION['pending_at'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                self::redirect('/admin/totp');
            }
            Support::recordFailure('admin', $ip);
            Support::audit($username !== '' ? $username : '?', 'login.failed');
            $error = 'Neispravno korisnicko ime ili lozinka.';
        }
        self::page('admin/login', ['error' => $error], false);
    }

    private static function totp(string $method, string $ip): void
    {
        $uid = (int)($_SESSION['pending_uid'] ?? 0);
        if ($uid === 0 || time() - (int)($_SESSION['pending_at'] ?? 0) > 300) {
            self::redirect('/admin/login');
        }
        $u = Db::one('SELECT * FROM admins WHERE id = ? AND disabled = 0', [$uid]);
        if ($u === null) {
            self::redirect('/admin/login');
        }
        $enroll = (int)$u['totp_confirmed'] === 0;
        if ($enroll && empty($u['totp_secret_enc'])) {
            $secret = Totp::generateSecret();
            Db::run('UPDATE admins SET totp_secret_enc = ? WHERE id = ?', [Crypto::encrypt($secret), $uid]);
            $u['totp_secret_enc'] = Crypto::encrypt($secret);
        }
        $secret = Crypto::decrypt((string)$u['totp_secret_enc']);
        $error = null;
        if ($method === 'POST') {
            self::checkCsrf();
            if (Support::isBlocked('admin', $ip, 8, 900)) {
                http_response_code(429);
                self::page('admin/totp', ['error' => 'Previse neuspjelih pokusaja.', 'enroll' => $enroll, 'secret' => $enroll ? $secret : null, 'uri' => null], false);
                return;
            }
            $step = Totp::verify($secret, (string)($_POST['code'] ?? ''), (int)$u['totp_last_step']);
            if ($step !== null) {
                Db::run('UPDATE admins SET totp_last_step = ?, totp_confirmed = 1 WHERE id = ?', [$step, $uid]);
                Support::clearFailures('admin', $ip);
                session_regenerate_id(true);
                unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
                $_SESSION['uid'] = $uid;
                $_SESSION['start'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                self::$user = $u;
                Support::audit((string)$u['username'], $enroll ? 'login.ok.2fa_enrolled' : 'login.ok');
                self::redirect('/admin/');
            }
            Support::recordFailure('admin', $ip);
            Support::audit((string)$u['username'], 'login.totp_failed');
            $error = 'Neispravan kod.';
        }
        self::page('admin/totp', [
            'error' => $error, 'enroll' => $enroll,
            'secret' => $enroll ? $secret : null,
            'uri' => $enroll ? Totp::uri($secret, (string)$u['username']) : null,
        ], false);
    }

    // ---------------------------------------------------------------- stranice

    /** @param array<string,mixed> $vars */
    private static function page(string $tpl, array $vars, bool $auth = true): void
    {
        $flash = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        $content = View::render($tpl, $vars + ['csrf' => $_SESSION['csrf']]);
        echo View::render('admin/layout', [
            'content' => $content, 'flash' => $flash, 'auth' => $auth,
            'username' => $auth ? (string)(self::$user['username'] ?? '') : '', 'csrf' => $_SESSION['csrf'],
        ]);
    }

    private static function dashboard(): void
    {
        $today = Clock::today();
        $todayMap = [];
        foreach (Db::all('SELECT location_id, feed, source, created_at, rows_count FROM uploads WHERE price_date = ?', [$today]) as $r) {
            $todayMap[$r['location_id'] . '|' . $r['feed']] = $r;
        }
        $lastMap = [];
        foreach (Db::all('SELECT location_id, feed, MAX(price_date) AS d FROM uploads GROUP BY location_id, feed') as $r) {
            $lastMap[$r['location_id'] . '|' . $r['feed']] = $r['d'];
        }
        $rows = [];
        $stats = ['ok' => 0, 'carry' => 0, 'missing' => 0];
        foreach (Locations::all(true) as $loc) {
            foreach (Locations::feeds($loc) as $feed) {
                $k = $loc['id'] . '|' . $feed;
                $t = $todayMap[$k] ?? null;
                $status = $t === null ? 'missing' : ($t['source'] === 'carryover' ? 'carry' : 'ok');
                $stats[$status]++;
                $rows[] = ['loc' => $loc, 'feed' => $feed, 'status' => $status, 'today' => $t, 'last' => $lastMap[$k] ?? null];
            }
        }
        usort($rows, static fn($a, $b) => [$a['status'] === 'missing' ? 0 : ($a['status'] === 'carry' ? 1 : 2), $a['loc']['grp'], $a['loc']['betnr']] <=> [$b['status'] === 'missing' ? 0 : ($b['status'] === 'carry' ? 1 : 2), $b['loc']['grp'], $b['loc']['betnr']]);
        self::page('admin/dashboard', ['rows' => $rows, 'stats' => $stats, 'today' => $today]);
    }

    private static function locations(string $method): void
    {
        self::page('admin/locations', ['locations' => Locations::all(false), 'concessions' => Locations::concessions()]);
    }

    private static function locationForm(?int $id, string $method): void
    {
        $loc = $id !== null ? Locations::byId($id) : null;
        if ($id !== null && $loc === null) {
            http_response_code(404);
            self::page('admin/notfound', []);
            return;
        }
        if ($method === 'POST') {
            $d = $_POST + [];
            foreach (array_keys(Locations::SOURCES) as $source) {
                $d['feed_' . $source] = $d['feed_' . $source] ?? null; // neoznacen checkbox = iskljucen izvor
            }
            if ($id === null) {
                $newId = Locations::create($d);
                Support::audit(self::$user['username'], 'location.create', strtolower(trim((string)$d['grp'])) . '/' . (int)$d['betnr']);
                self::rebuild();
                self::flash('ok', 'Lokacija je dodana.');
                self::redirect('/admin/locations/' . $newId);
            }
            Locations::update($id, $d);
            Support::audit(self::$user['username'], 'location.update', $loc['grp'] . '/' . $loc['betnr']);
            self::rebuild();
            self::flash('ok', 'Spremljeno.');
            self::redirect('/admin/locations/' . $id);
        }
        self::page('admin/location_form', ['loc' => $loc, 'concessions' => Locations::concessions()]);
    }

    private static function concessions(string $method): void
    {
        if ($method === 'POST') {
            $grp = strtolower(trim((string)($_POST['grp'] ?? '')));
            $naziv = trim((string)($_POST['naziv'] ?? ''));
            $url = trim((string)($_POST['web_url'] ?? ''));
            if ($naziv === '' || mb_strlen($naziv) > 200) {
                throw new InvalidArgumentException('Naziv je obavezan (max 200 znakova).');
            }
            if ($url !== '' && preg_match('#^https?://[^\s<>"\']{3,280}$#i', $url) !== 1) {
                throw new InvalidArgumentException('Web adresa mora poceti s http:// ili https://');
            }
            Locations::ensureConcession($grp, $naziv);
            Db::run('UPDATE concessions SET naziv = ?, web_url = ? WHERE grp = ?', [$naziv, $url !== '' ? $url : null, $grp]);
            if (array_key_exists('slug', $_POST)) {
                $before = Locations::pathSlug($grp);
                Locations::setSlug($grp, (string)$_POST['slug']);
                if ($before !== Locations::pathSlug($grp)) {
                    Support::audit(self::$user['username'], 'concession.slug', $grp . ': ' . $before . ' -> ' . Locations::pathSlug($grp));
                }
            }
            Support::audit(self::$user['username'], 'concession.save', $grp);
            self::rebuild();
            self::flash('ok', 'Koncesija je spremljena.');
            self::redirect('/admin/concessions');
        }
        self::page('admin/concessions', ['concessions' => Locations::concessions()]);
    }

    private static function keys(string $method): void
    {
        $new = null;
        if ($method === 'POST') {
            if (($_POST['action'] ?? '') === 'revoke') {
                Keys::revoke((int)($_POST['id'] ?? 0));
                Support::audit(self::$user['username'], 'key.revoke', (string)(int)($_POST['id'] ?? 0));
                self::flash('ok', 'Kljuc je opozvan.');
                self::redirect('/admin/keys');
            }
            $scope = (string)($_POST['scope'] ?? '');
            $new = Keys::create(
                $scope,
                $scope === 'group' ? strtolower((string)($_POST['grp'] ?? '')) : null,
                $scope === 'location' ? (int)($_POST['location_id'] ?? 0) : null,
                (string)($_POST['label'] ?? '')
            );
            Support::audit(self::$user['username'], 'key.create', $new['key_id'] . ' scope=' . $scope);
        }
        self::page('admin/keys', ['keys' => Keys::all(), 'concessions' => Locations::concessions(), 'locations' => Locations::all(false), 'new' => $new]);
    }

    private static function upload(string $method): void
    {
        if ($method === 'POST') {
            $loc = Locations::byId((int)($_POST['location_id'] ?? 0));
            $feed = (string)($_POST['feed'] ?? '');
            if ($loc === null || !CsvSpec::isFeed($feed) || !in_array($feed, Locations::feeds($loc), true)) {
                throw new InvalidArgumentException('Odaberi lokaciju i feed.');
            }
            $today = Clock::today();
            $date = ($_POST['date'] ?? 'today') === 'yesterday' ? (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d') : $today;
            $f = $_FILES['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                throw new InvalidArgumentException('Upload nije uspio (provjeri velicinu datoteke).');
            }
            $res = CsvSpec::validate((string)$f['tmp_name'], $feed, (int)Config::get('max_rows', 2000000));
            if (!$res['ok']) {
                throw new InvalidArgumentException('CSV nije valjan: ' . implode(' | ', array_slice($res['errors'], 0, 5)));
            }
            Publisher::publish($loc, $feed, $date, (string)$f['tmp_name'], $res['rows'], 'manual', null);
            Support::audit(self::$user['username'], 'upload.manual', $loc['grp'] . '/' . $loc['betnr'] . '/' . $feed . ' ' . $date . ' rows=' . $res['rows']);
            self::flash('ok', 'Objavljeno: ' . $res['rows'] . ' redaka za ' . $date . '.');
            self::redirect('/admin/upload');
        }
        self::page('admin/upload', ['locations' => Locations::all(true)]);
    }

    private static function import(string $method): void
    {
        if ($method === 'POST') {
            $f = $_FILES['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                throw new InvalidArgumentException('Upload nije uspio.');
            }
            if (($_POST['kind'] ?? 'locations') === 'concessions') {
                $r = Locations::importConcessionsCsv((string)$f['tmp_name']);
                Support::audit(self::$user['username'], 'concessions.import', 'created=' . $r['created'] . ' updated=' . $r['updated']);
                self::rebuild();
                self::flash('ok', 'Uvoz koncesija gotov: dodano ' . $r['created'] . ', azurirano ' . $r['updated'] . '.');
                self::redirect('/admin/concessions');
            }
            $r = Locations::importCsv((string)$f['tmp_name']);
            Support::audit(self::$user['username'], 'locations.import', 'created=' . $r['created'] . ' updated=' . $r['updated'] . ' skipped=' . $r['skipped']);
            self::rebuild();
            self::flash('ok', 'Uvoz lokacija gotov: dodano ' . $r['created'] . ', azurirano ' . $r['updated'] . ', preskoceno ' . $r['skipped'] . '.');
            self::redirect('/admin/locations');
        }
        self::page('admin/import', []);
    }

    private static function password(string $method): void
    {
        if ($method === 'POST') {
            $cur = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            if (!password_verify($cur, (string)self::$user['pass_hash'])) {
                throw new InvalidArgumentException('Trenutna lozinka nije ispravna.');
            }
            if (strlen($new) < 12 || $new !== (string)($_POST['new2'] ?? '')) {
                throw new InvalidArgumentException('Nova lozinka mora imati barem 12 znakova i podudarati se u oba polja.');
            }
            Db::run('UPDATE admins SET pass_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), self::$user['id']]);
            Support::audit(self::$user['username'], 'password.change');
            self::flash('ok', 'Lozinka je promijenjena.');
            self::redirect('/admin/password');
        }
        self::page('admin/password', []);
    }

    private static function rebuild(): void
    {
        try {
            Pages::renderAll();
        } catch (Throwable $t) {
            error_log('cjenik: renderAll nije uspio: ' . $t->getMessage());
            self::flash('err', 'Spremljeno, ali generiranje javnih stranica nije uspjelo (vidi log).');
        }
    }
}
