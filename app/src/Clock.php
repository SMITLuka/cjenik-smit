<?php
declare(strict_types=1);

namespace Cjenik;

use DateTimeImmutable;
use DateTimeZone;

/** Vrijeme u zoni aplikacije; moze se zamrznuti u testovima. */
final class Clock
{
    private static ?DateTimeImmutable $fixed = null;

    public static function set(?DateTimeImmutable $now): void
    {
        self::$fixed = $now;
    }

    public static function now(): DateTimeImmutable
    {
        if (self::$fixed !== null) {
            return self::$fixed;
        }
        return new DateTimeImmutable('now', new DateTimeZone((string)Config::get('timezone', 'Europe/Zagreb')));
    }

    public static function today(): string
    {
        return self::now()->format('Y-m-d');
    }

    public static function utcStamp(): string
    {
        return gmdate('Y-m-d H:i:s', self::now()->getTimestamp());
    }
}
