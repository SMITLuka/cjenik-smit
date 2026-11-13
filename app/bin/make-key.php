<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

use Cjenik\Keys;
use Cjenik\Locations;

// php app/bin/make-key.php global "agent VPN"
// php app/bin/make-key.php group ka4 "ka4 agent"
// php app/bin/make-key.php location ka4/1 "ka4/1 agent"
$scope = (string)($argv[1] ?? '');
try {
    if ($scope === 'global') {
        $k = Keys::create('global', null, null, (string)($argv[2] ?? 'agent'));
    } elseif ($scope === 'group') {
        $k = Keys::create('group', strtolower((string)($argv[2] ?? '')), null, (string)($argv[3] ?? 'agent'));
    } elseif ($scope === 'location') {
        [$g, $b] = array_pad(explode('/', (string)($argv[2] ?? '')), 2, '');
        $loc = Locations::find(strtolower($g), (int)$b);
        if ($loc === null) {
            throw new InvalidArgumentException('Lokacija ne postoji.');
        }
        $k = Keys::create('location', null, (int)$loc['id'], (string)($argv[3] ?? 'agent'));
    } else {
        fwrite(STDERR, "Upotreba: make-key.php global [oznaka] | group <grp> [oznaka] | location <grp/betnr> [oznaka]\n");
        exit(1);
    }
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
echo "KEY_ID={$k['key_id']}\nSECRET={$k['secret']}\n(Tajna se vise nece moci procitati — spremi je sada.)\n";
