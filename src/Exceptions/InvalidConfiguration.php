<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Exceptions;

/**
 * Raised when the package configuration is incomplete, invalid or refers to an unknown connection.
 */
final class InvalidConfiguration extends AutentiException
{
    public static function missing(string $key, string $env): self
    {
        return new self(sprintf('Autenti "%s" is not configured. Set %s in the environment.', $key, $env));
    }

    public static function invalid(string $key, string $expected): self
    {
        return new self(sprintf('Autenti "%s" must be %s.', $key, $expected));
    }

    public static function unknownConnection(string $name): self
    {
        return new self(sprintf('Autenti connection "%s" is not defined in config/autenti.php.', $name));
    }

    public static function unreadableKey(string $reason): self
    {
        return new self('Autenti private key cannot be used: ' . $reason . '.');
    }
}
