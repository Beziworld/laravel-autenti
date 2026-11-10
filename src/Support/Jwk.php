<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Support;

/**
 * Conversion of RSA JSON Web Keys (RFC 7517) to PEM public keys.
 *
 * @internal
 */
final class Jwk
{
    /**
     * PEM SubjectPublicKeyInfo of an RSA JWK, or null when the key is not a usable RSA key.
     *
     * @param array<array-key, mixed> $jwk
     */
    public static function toPem(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            return null;
        }

        $modulus = Base64Url::decode($jwk['n']);
        $exponent = Base64Url::decode($jwk['e']);

        if ($modulus === null || $exponent === null || $modulus === '' || $exponent === '') {
            return null;
        }

        $rsaPublicKey = self::sequence(self::integer($modulus) . self::integer($exponent));
        $algorithm = self::sequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" . "\x05\x00");
        $spki = self::sequence($algorithm . "\x03" . self::length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey);

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || ord($bytes[0]) > 0x7f) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::length(strlen($bytes)) . $bytes;
    }

    private static function sequence(string $content): string
    {
        return "\x30" . self::length(strlen($content)) . $content;
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }
}
