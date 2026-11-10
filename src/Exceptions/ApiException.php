<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Exceptions;

use Throwable;

/**
 * Raised when Autenti rejects a request, answers unexpectedly or cannot be reached.
 *
 * Autenti errors carry a hierarchical type (e.g. "/error/resource-not-found"
 * or "/error/authentication/token/invalid"), a title, a detail and an
 * instance identifier that can be quoted to the Autenti support.
 */
final class ApiException extends AutentiException
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly ?string $type = null,
        private readonly ?string $instance = null,
        private readonly array $payload = [],
        private readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus ?? 0, $previous);
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function fromResponse(array $payload, int $httpStatus, ?int $retryAfter = null): self
    {
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : null;
        $parts = array_values(array_filter([
            is_string($payload['title'] ?? null) ? $payload['title'] : null,
            is_string($payload['detail'] ?? null) ? $payload['detail'] : null,
            is_string($payload['error'] ?? null) ? $payload['error'] : null,
        ], static fn(?string $part): bool => $part !== null && $part !== ''));

        $message = $parts !== [] ? implode(': ', array_unique($parts)) : sprintf('request failed with HTTP status %d', $httpStatus);

        if ($type !== null) {
            $message .= ' [' . $type . ']';
        }

        if ($httpStatus === 429) {
            $message = 'rate limit exceeded' . ($retryAfter !== null ? sprintf(', retry after %d seconds', $retryAfter) : '');
        }

        return new self(
            sprintf('Autenti error (HTTP %d): %s', $httpStatus, $message),
            $httpStatus,
            $type,
            is_string($payload['instance'] ?? null) ? $payload['instance'] : null,
            $payload,
            $retryAfter,
        );
    }

    public static function transport(Throwable $previous): self
    {
        return new self('Could not connect to Autenti: ' . $previous->getMessage(), previous: $previous);
    }

    public static function unexpectedResponse(string $what, ?int $httpStatus = null): self
    {
        return new self(sprintf('Autenti returned an unexpected response: %s.', $what), $httpStatus);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * Hierarchical error type, e.g. "/error/resource-not-found".
     */
    public function type(): ?string
    {
        return $this->type;
    }

    /**
     * Identifier of the issue to quote in support requests.
     */
    public function instance(): ?string
    {
        return $this->instance;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Whether the error type starts with the given prefix, e.g. "/error/authentication".
     */
    public function isType(string $prefix): bool
    {
        return $this->type !== null && str_starts_with($this->type, $prefix);
    }

    public function isNotFound(): bool
    {
        return $this->httpStatus === 404 || $this->isType('/error/resource-not-found');
    }

    public function isUnauthorized(): bool
    {
        return $this->httpStatus === 401;
    }

    public function isRateLimited(): bool
    {
        return $this->httpStatus === 429;
    }
}
