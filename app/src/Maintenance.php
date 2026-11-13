<?php
declare(strict_types=1);

namespace Cjenik;

/** Periodicki poslovi (cron svakih 5 min): retencija, ciscenje, watchdog (upozorenje + prijenos jucerasnjeg). */
final class Maintenance
{
    /** @return array<string,mixed> sazetak (za log/testove) */
    public static function run(): array
    {
        $now = Clock::now();
        $today = Clock::today();
        $summary = ['retention_deleted' => Publisher::retention(), 'missing' => [], 'carried' => [], 'no_previous' => []];

        Db::run('DELETE FROM nonces WHERE ts < ?', [$now->getTimestamp() - 3600]);
        Db::run('DELETE FROM auth_failures WHERE ts < ?', [$now->getTimestamp() - 86400]);

        $wd = (array)Config::get('watchdog', []);
        if (!empty($wd['weekdays_only']) && (int)$now->format('N') >= 6) {
            return $summary;
        }
        $hm = $now->format('H:i');
        $alertAt = (string)($wd['alert_at'] ?? '07:40');
        $carryAt = (string)($wd['carryover_at'] ?? '07:55');

        $missing = self::missing($today);
        $summary['missing'] = array_map(static fn($m) => $m['label'], $missing);

        if ($missing !== [] && $hm >= $alertAt && Support::getState('alert_sent:' . $today) === null) {
            Support::setState('alert_sent:' . $today, Clock::utcStamp());
            Support::notify(
                'Nedostaju cjenici za ' . $today . ' (' . count($missing) . ')',
                "Do $alertAt nije stigao danasnji cjenik za:\n- " . implode("\n- ", $summary['missing'])
                . "\n\nU $carryAt automatski se prenosi jucerasnji cjenik."
            );
        }

        if ($missing !== [] && $hm >= $carryAt) {
            foreach ($missing as $m) {
                $prev = Publisher::previousUpload((int)$m['loc']['id'], $m['feed'], $today);
                if ($prev === null) {
                    $summary['no_previous'][] = $m['label'];
                    continue;
                }
                $src = Publisher::feedDir($m['loc'], $m['feed']) . '/' . Publisher::fileNameOf($prev);
                if (!is_file($src)) {
                    $summary['no_previous'][] = $m['label'];
                    continue;
                }
                Publisher::publish($m['loc'], $m['feed'], $today, $src, (int)$prev['rows_count'], 'carryover', null, false);
                $summary['carried'][] = $m['label'];
            }
            if ($summary['carried'] !== [] || $summary['no_previous'] !== []) {
                Pages::renderAll();
                if (Support::getState('carry_sent:' . $today) === null) {
                    Support::setState('carry_sent:' . $today, Clock::utcStamp());
                    Support::notify(
                        'Prenesen jucerasnji cjenik (' . count($summary['carried']) . ')',
                        "Prenesen jucerasnji cjenik za:\n- " . (implode("\n- ", $summary['carried']) ?: '(nista)')
                        . ($summary['no_previous'] ? "\n\nNema prethodnog cjenika za:\n- " . implode("\n- ", $summary['no_previous']) : '')
                    );
                }
            }
        }
        return $summary;
    }

    /** @return list<array{loc:array<string,mixed>, feed:string, label:string}> */
    public static function missing(string $date): array
    {
        $have = [];
        foreach (Db::all('SELECT location_id, feed FROM uploads WHERE price_date = ?', [$date]) as $r) {
            $have[$r['location_id'] . '|' . $r['feed']] = true;
        }
        $out = [];
        foreach (Locations::all(true) as $loc) {
            foreach (Locations::feeds($loc) as $feed) {
                if (!isset($have[$loc['id'] . '|' . $feed])) {
                    $out[] = ['loc' => $loc, 'feed' => $feed, 'label' => strtoupper((string)$loc['grp']) . '/' . $loc['betnr'] . ' ' . $loc['naziv'] . ' [' . $feed . ']'];
                }
            }
        }
        return $out;
    }
}
