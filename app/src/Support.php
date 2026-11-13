<?php
declare(strict_types=1);

namespace Cjenik;

/** Mali pomocnici: audit, throttle (neuspjeli pokusaji prijave), state, obavijesti. */
final class Support
{
    public static function audit(string $actor, string $action, string $detail = ''): void
    {
        Db::run(
            'INSERT INTO audit_log (ts, actor, ip, action, detail) VALUES (?,?,?,?,?)',
            [Clock::utcStamp(), $actor, self::ipForLog(), $action, $detail]
        );
    }

    private static function ipForLog(): string
    {
        return PHP_SAPI === 'cli' ? 'cli' : Ip::client();
    }

    public static function recordFailure(string $scope, string $ip): void
    {
        Db::run('INSERT INTO auth_failures (scope, ip, ts) VALUES (?,?,?)', [$scope, $ip, Clock::now()->getTimestamp()]);
    }

    public static function isBlocked(string $scope, string $ip, int $max, int $windowSeconds): bool
    {
        $since = Clock::now()->getTimestamp() - $windowSeconds;
        $n = (int)Db::val('SELECT COUNT(*) FROM auth_failures WHERE scope = ? AND ip = ? AND ts >= ?', [$scope, $ip, $since]);
        return $n >= $max;
    }

    public static function clearFailures(string $scope, string $ip): void
    {
        Db::run('DELETE FROM auth_failures WHERE scope = ? AND ip = ?', [$scope, $ip]);
    }

    public static function getState(string $k): ?string
    {
        $v = Db::val('SELECT v FROM state WHERE k = ?', [$k]);
        return $v === null ? null : (string)$v;
    }

    public static function setState(string $k, string $v): void
    {
        if (Db::val('SELECT COUNT(*) FROM state WHERE k = ?', [$k]) > 0) {
            Db::run('UPDATE state SET v = ? WHERE k = ?', [$v, $k]);
        } else {
            Db::run('INSERT INTO state (k, v) VALUES (?,?)', [$k, $v]);
        }
    }

    /** E-mail upozorenje adminu; uvijek zapisuje i u log da ne ovisi o mail() konfiguraciji. */
    public static function notify(string $subject, string $body): void
    {
        if (defined('CJENIK_TESTING')) {
            return;
        }
        error_log('cjenik notify: ' . $subject . ' | ' . str_replace("\n", ' / ', $body));
        $to = (string)Config::get('alert_email', '');
        if ($to === '') {
            return;
        }
        $from = (string)Config::get('mail_from', '');
        $headers = $from !== '' ? 'From: ' . $from . "\r\nContent-Type: text/plain; charset=UTF-8" : 'Content-Type: text/plain; charset=UTF-8';
        @mail($to, '=?UTF-8?B?' . base64_encode('[cjenik] ' . $subject) . '?=', $body, $headers);
    }

    public static function isReservedSlug(string $slug): bool
    {
        return in_array($slug, ['api', 'admin', 'assets', 'index', 'format', 'robots', 'sitemap', 'static', 'storage', 'app'], true);
    }

    public static function validGrp(string $grp): bool
    {
        return preg_match('/^[a-z0-9]{2,8}$/', $grp) === 1 && !self::isReservedSlug($grp);
    }

    /** Javna oznaka koncesije u URL-u (npr. 'autocentarroca'): mala slova, brojke, crtica izmedju. */
    public static function validSlug(string $slug): bool
    {
        return strlen($slug) >= 2 && strlen($slug) <= 60
            && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) === 1 && !self::isReservedSlug($slug);
    }

    /** Naziv -> slug: bez dijakritika, razmaka i tocaka ("auto centar roca" -> "autocentarroca"). */
    public static function slugify(string $naziv): string
    {
        $s = strtr(mb_strtolower($naziv), ['č' => 'c', 'ć' => 'c', 'š' => 's', 'ž' => 'z', 'đ' => 'dj']);
        $s = preg_replace('/\s*-\s*/', '-', $s) ?? '';
        $s = preg_replace('/[^a-z0-9-]+/', '', $s) ?? '';
        $s = preg_replace('/-+/', '-', $s) ?? '';
        return trim($s, '-');
    }
}
