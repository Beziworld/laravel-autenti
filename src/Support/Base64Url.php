<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Support;

/**
 * Base64url without padding (RFC 4648 section 5), as used by JWT, JWS and Autenti assertions.
 */
final class Base64Url
{
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decodes base64url as well as standard base64, with or without padding.
     */
    public static function decode(string $data): ?string
    {
        $data = strtr(trim($data), '-_', '+/');
        $remainder = strlen($data) % 4;

        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($data, true);

        return $decoded === false ? null : $decoded;
    }
}
