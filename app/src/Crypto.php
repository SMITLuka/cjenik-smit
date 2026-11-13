<?php
declare(strict_types=1);

namespace Cjenik;

use RuntimeException;

/** AES-256-GCM za tajne u bazi (HMAC kljucevi, TOTP). Kljuc je app_key iz config.php. */
final class Crypto
{
    private static function key(): string
    {
        $k = (string)Config::get('app_key', '');
        if (!str_starts_with($k, 'base64:')) {
            throw new RuntimeException('app_key nije postavljen');
        }
        $raw = base64_decode(substr($k, 7), true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('app_key mora biti 32 bajta (base64)');
        }
        return $raw;
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('encrypt failed');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $blob): string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('decrypt: neispravan zapis');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new RuntimeException('decrypt failed');
        }
        return $plain;
    }
}
