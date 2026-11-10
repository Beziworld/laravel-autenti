<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Support\Values;

/**
 * Entry link of a participant (HTTP_REQUEST challenge) leading to an action in the Autenti UI.
 *
 * action is the resulting event type, e.g.
 * "EVENT_CLASSIFIER-UNIQUE_TYPE:SIGNATURE_APPLICATION". Links can be
 * created only after the document was sent and expire after 30 days.
 */
final readonly class ActionLink
{
    /**
     * @param list<string> $classifiers
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $uri,
        public string $method = 'GET',
        public ?string $action = null,
        public ?string $description = null,
        public array $classifiers = [],
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $attributes = Values::array($data['attributes'] ?? null);
        $request = Values::array($attributes['httpRequest'] ?? null);

        return new self(
            uri: Values::string($request['uri'] ?? null) ?? '',
            method: strtoupper(Values::string($request['method'] ?? null) ?? 'GET'),
            action: Values::string($attributes['actionIdentifier'] ?? null),
            description: Values::string($attributes['description'] ?? null),
            classifiers: Values::strings($data['classifiers'] ?? null),
            raw: $data,
        );
    }

    public function isSigning(): bool
    {
        return $this->action === Event::SIGNATURE_APPLICATION;
    }
}
