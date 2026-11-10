<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Support;

use Beziworld\Autenti\Exceptions\InvalidConfiguration;
use OpenSSLAsymmetricKey;

/**
 * Compact JWS signing and verification with RSA keys (RS256, RS384, RS512).
 */
final class Jwt
{
    private const array ALGORITHMS = [
        'RS256' => OPENSSL_ALGO_SHA256,
        'RS384' => OPENSSL_ALGO_SHA384,
        'RS512' => OPENSSL_ALGO_SHA512,
    ];

    /**
     * Signs the claims and returns a compact JWT.
     *
     * @param array<string, mixed> $claims
     * @param string $privateKey PEM string, PEM with escaped "\n" sequences (as stored in .env) or a path to a PEM file.
     *
     * @throws InvalidConfiguration When the key cannot be read or used.
     */
    public static function sign(array $claims, #[\SensitiveParameter] string $privateKey, string $algorithm = 'RS256', ?string $keyId = null, #[\SensitiveParameter] ?string $passphrase = null): string
    {
        $header = ['alg' => $algorithm, 'typ' => 'JWT'];

        if ($keyId !== null) {
            $header['kid'] = $keyId;
        }

        $input = Base64Url::encode(json_encode($header, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            . '.' . Base64Url::encode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $key = self::privateKey($privateKey, $passphrase);
        $signature = '';

        if (! openssl_sign($input, $signature, $key, self::algorithm($algorithm))) {
            throw InvalidConfiguration::unreadableKey('OpenSSL could not sign with it');
        }

        return $input . '.' . Base64Url::encode($signature);
    }

    /**
     * Verifies an RSA signature over the JWS signing input.
     */
    public static function verify(string $signingInput, string $signature, string $publicKeyPem, string $algorithm): bool
    {
        if (! isset(self::ALGORITHMS[$algorithm])) {
            return false;
        }

        return openssl_verify($signingInput, $signature, $publicKeyPem, self::ALGORITHMS[$algorithm]) === 1;
    }

    /**
     * Decodes the header or payload segment of a JWS into an array.
     *
     * @return array<array-key, mixed>|null
     */
    public static function decodeSegment(string $segment): ?array
    {
        $json = Base64Url::decode($segment);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @throws InvalidConfiguration
     */
    private static function privateKey(#[\SensitiveParameter] string $key, #[\SensitiveParameter] ?string $passphrase): OpenSSLAsymmetricKey
    {
        $pem = str_contains($key, '-----BEGIN') ? str_replace('\n', "\n", $key) : null;

        if ($pem === null) {
            if (! is_file($key) || ! is_readable($key)) {
                throw InvalidConfiguration::unreadableKey('it is neither a PEM string nor a readable file');
            }

            $pem = (string) file_get_contents($key);
        }

        $resource = openssl_pkey_get_private($pem, $passphrase);

        if ($resource === false) {
            throw InvalidConfiguration::unreadableKey('OpenSSL could not parse it (wrong format or passphrase)');
        }

        $details = openssl_pkey_get_details($resource);

        if (! is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($details['bits'] ?? 0) < 2048) {
            throw InvalidConfiguration::unreadableKey('Autenti requires an RSA key of at least 2048 bits');
        }

        return $resource;
    }

    /**
     * @throws InvalidConfiguration
     */
    private static function algorithm(string $algorithm): int
    {
        return self::ALGORITHMS[$algorithm] ?? throw InvalidConfiguration::invalid('algorithm', 'RS256, RS384 or RS512');
    }
}
