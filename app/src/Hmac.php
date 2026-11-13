<?php
declare(strict_types=1);

namespace Cjenik;

/**
 * Potpis zahtjeva. Kanonski niz (agent mora identicno):
 *   METHOD \n REQUEST_URI(putanja+query kako je poslano) \n TIMESTAMP \n NONCE \n SHA256_HEX(tijelo kako je poslano)
 * Potpis = hex(HMAC-SHA256(tajna, kanonski niz)).
 */
final class Hmac
{
    public static function sign(string $secret, string $method, string $uri, string $ts, string $nonce, string $bodySha256Hex): string
    {
        return hash_hmac('sha256', strtoupper($method) . "\n" . $uri . "\n" . $ts . "\n" . $nonce . "\n" . $bodySha256Hex, $secret);
    }
}
