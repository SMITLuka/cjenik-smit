<?php
declare(strict_types=1);

namespace Cjenik;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function set(?PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $db = (array)Config::get('db', []);
            self::$pdo = new PDO((string)($db['dsn'] ?? ''), (string)($db['user'] ?? ''), (string)($db['pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
        return self::$pdo;
    }

    public static function isSqlite(): bool
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }

    /** @param array<int|string,mixed> $p @return list<array<string,mixed>> */
    public static function all(string $sql, array $p = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    /** @param array<int|string,mixed> $p @return array<string,mixed>|null */
    public static function one(string $sql, array $p = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    /** @param array<int|string,mixed> $p */
    public static function val(string $sql, array $p = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        $r = $st->fetchColumn();
        return $r === false ? null : $r;
    }

    /** @param array<int|string,mixed> $p */
    public static function run(string $sql, array $p = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    /** @param array<int|string,mixed> $p */
    public static function insert(string $sql, array $p = []): int
    {
        self::run($sql, $p);
        return (int)self::pdo()->lastInsertId();
    }
}
