<?php
declare(strict_types=1);

namespace Cjenik;

final class Ip
{
    /** Stvarna IP klijenta. Header se koristi samo ako je izricito konfiguriran (iza pouzdanog proxyja). */
    /** @param array<string,mixed>|null $server */
    public static function client(?array $server = null): string
    {
        $s = $server ?? $_SERVER;
        $header = Config::get('client_ip_header');
        if (is_string($header) && $header !== '' && !empty($s[$header])) {
            // X-Forwarded-For: client, proxy1, proxy2 -> prvi je klijent
            $first = trim(explode(',', (string)$s[$header])[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        return (string)($s['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /** @param list<string> $list pojedinacne IP ili CIDR; prazna lista = dopusti sve */
    public static function allowed(string $ip, array $list): bool
    {
        if ($list === []) {
            return true;
        }
        foreach ($list as $entry) {
            if (self::match($ip, trim($entry))) {
                return true;
            }
        }
        return false;
    }

    public static function match(string $ip, string $entry): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }
        if (!str_contains($entry, '/')) {
            $e = @inet_pton($entry);
            return $e !== false && $e === $ipBin;
        }
        [$net, $bits] = explode('/', $entry, 2);
        $netBin = @inet_pton($net);
        if ($netBin === false || strlen($netBin) !== strlen($ipBin) || !ctype_digit($bits)) {
            return false;
        }
        $bits = (int)$bits;
        if ($bits < 0 || $bits > strlen($netBin) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
            return false;
        }
        $rem = $bits % 8;
        if ($rem === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rem)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask);
    }
}
