<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Exceptions;

use Beziworld\Autenti\Data\Challenge;

/**
 * Raised when the authorization server rejects the token request.
 *
 * error() is the OAuth2 error code (invalid_client, invalid_grant, ...).
 * "insufficient_authentication" means that the user configured
 * multi-factor authentication; challenge() then describes the requested
 * factor.
 */
final class AuthenticationFailed extends AutentiException
{
    public function __construct(
        string $message,
        private readonly ?string $error = null,
        private readonly ?int $httpStatus = null,
        private readonly ?Challenge $challenge = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0);
    }

    public static function rejected(?string $error, int $httpStatus, ?string $description = null, ?Challenge $challenge = null): self
    {
        $message = sprintf(
            'Autenti rejected the token request (HTTP %d%s)%s',
            $httpStatus,
            $error !== null ? ', ' . $error : '',
            $description !== null && $description !== '' ? ': ' . $description : '',
        );

        if ($error === 'insufficient_authentication') {
            $message .= '. The user requires multi-factor authentication, which a server integration cannot complete.';
        }

        return new self($message, $error, $httpStatus, $challenge);
    }

    public static function unexpected(string $what): self
    {
        return new self('Autenti returned an unexpected token response: ' . $what . '.');
    }

    public function error(): ?string
    {
        return $this->error;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function challenge(): ?Challenge
    {
        return $this->challenge;
    }

    public function requiresMultiFactor(): bool
    {
        return $this->error === 'insufficient_authentication';
    }
}
