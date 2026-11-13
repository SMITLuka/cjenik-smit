<?php
declare(strict_types=1);

namespace Cjenik;

use InvalidArgumentException;

/**
 * API kljucevi za ingest. Svaki kljuc ima key_id (javni) i tajnu (HMAC). Tajna je u bazi sifrirana (Crypto).
 * Opseg (scope): global (sve lokacije), group (jedna koncesija), location (jedna lokacija).
 */
final class Keys
{
    /**
     * @return array{key_id:string, secret:string} tajna se prikazuje samo jednom
     */
    public static function create(string $scope, ?string $grp, ?int $locationId, string $label): array
    {
        if (!in_array($scope, ['global', 'group', 'location'], true)) {
            throw new InvalidArgumentException('Neispravan opseg.');
        }
        if ($scope === 'group' && ($grp === null || Db::val('SELECT COUNT(*) FROM concessions WHERE grp = ?', [$grp]) == 0)) {
            throw new InvalidArgumentException('Koncesija ne postoji.');
        }
        if ($scope === 'location' && ($locationId === null || Locations::byId($locationId) === null)) {
            throw new InvalidArgumentException('Lokacija ne postoji.');
        }
        $keyId = 'k_' . bin2hex(random_bytes(8));
        $secret = bin2hex(random_bytes(32));
        Db::run(
            'INSERT INTO api_keys (key_id, secret_enc, scope, grp, location_id, label, created_at) VALUES (?,?,?,?,?,?,?)',
            [$keyId, Crypto::encrypt($secret), $scope, $scope === 'group' ? $grp : null, $scope === 'location' ? $locationId : null, mb_substr(trim($label), 0, 100), Clock::utcStamp()]
        );
        return ['key_id' => $keyId, 'secret' => $secret];
    }

    public static function revoke(int $id): void
    {
        Db::run('UPDATE api_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL', [Clock::utcStamp(), $id]);
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Db::all('SELECT id, key_id, scope, grp, location_id, label, created_at, revoked_at, last_used_at FROM api_keys ORDER BY id DESC');
    }

    /** @param array<string,mixed> $key @param array<string,mixed> $loc */
    public static function allows(array $key, array $loc): bool
    {
        return match ($key['scope']) {
            'global' => true,
            'group' => $key['grp'] === $loc['grp'],
            'location' => (int)$key['location_id'] === (int)$loc['id'],
            default => false,
        };
    }
}
