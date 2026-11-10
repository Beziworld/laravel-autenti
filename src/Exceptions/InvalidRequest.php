<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Exceptions;

/**
 * Raised before sending when request data violates documented Autenti constraints.
 */
final class InvalidRequest extends AutentiException
{
    public static function because(string $reason): self
    {
        return new self('Invalid Autenti request: ' . $reason . '.');
    }
}
