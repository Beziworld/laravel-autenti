<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Exceptions;

use Beziworld\Autenti\Data\Challenge;

/**
 * Raised when Autenti answers HTTP 403 with an X-Challenge header.
 *
 * A challenge asks for more information before the request can succeed,
 * for example the selection of an action, a participant list or an
 * additional authentication factor. challenge() describes it; answer it
 * with AutentiClient::performAction() and additional assertions.
 */
final class ChallengeRequired extends AutentiException
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        private readonly Challenge $challenge,
        private readonly array $payload = [],
    ) {
        parent::__construct(sprintf('Autenti requested a challenge: %s.', $challenge->classifiers !== [] ? implode(', ', $challenge->classifiers) : 'unknown'), 403);
    }

    public function challenge(): Challenge
    {
        return $this->challenge;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
