<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Exceptions;

/**
 * Raised when an incoming callback is not authentic or cannot be read.
 *
 * isAuthenticationFailure() distinguishes signature problems (HTTP 403)
 * from malformed bodies (HTTP 400).
 */
final class InvalidCallback extends AutentiException
{
    private bool $authentication = true;

    public static function missingSignature(): self
    {
        return new self('Autenti callback has no x-jws-signature header.');
    }

    public static function invalidSignature(string $reason): self
    {
        return new self('Autenti callback signature is invalid: ' . $reason . '.');
    }

    public static function unknownKey(string $kid): self
    {
        return new self(sprintf('Autenti callback is signed with an unknown key "%s".', $kid));
    }

    public static function malformed(string $reason): self
    {
        $exception = new self('Autenti callback is malformed: ' . $reason . '.');
        $exception->authentication = false;

        return $exception;
    }

    public function isAuthenticationFailure(): bool
    {
        return $this->authentication;
    }
}
