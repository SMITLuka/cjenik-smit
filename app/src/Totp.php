<?php
declare(strict_types=1);

namespace Cjenik;

/** TOTP (RFC 6238): HMAC-SHA1, 6 znamenki, 30 s. Radi s Google/Microsoft Authenticator, Aegis, 1Password... */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secretBase32, int $step): string
    {
        $key = self::base32Decode($secretBase32);
        $bin = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
        $o = ord($bin[19]) & 0x0F;
        $n = ((ord($bin[$o]) & 0x7F) << 24) | (ord($bin[$o + 1]) << 16) | (ord($bin[$o + 2]) << 8) | ord($bin[$o + 3]);
        return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return int|null korak (time step) koji je pogodjen, ili null; $lastStep sprjecava ponovnu upotrebu istog koda
     */
    public static function verify(string $secretBase32, string $input, int $lastStep, ?int $now = null): ?int
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';
        if (preg_match('/^\d{6}$/', $input) !== 1) {
            return null;
        }
        $cur = intdiv($now ?? time(), 30);
        for ($s = $cur - 1; $s <= $cur + 1; $s++) {
            if ($s > $lastStep && hash_equals(self::code($secretBase32, $s), $input)) {
                return $s;
            }
        }
        return null;
    }

    public static function uri(string $secret, string $account, string $issuer = 'Cjenici'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&digits=6&period=30';
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($s, '='))) as $c) {
            $p = strpos(self::ALPHABET, $c);
            if ($p === false) {
                continue;
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
