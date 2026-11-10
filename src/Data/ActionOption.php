<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Support\Values;

/**
 * Option of a selection challenge: an available action or a participant to remind.
 *
 * Action identifiers are deterministic event types, e.g.
 * "EVENT_CLASSIFIER-UNIQUE_TYPE:DOCUMENT_SENT".
 */
final readonly class ActionOption
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public ?string $description = null,
        public ?string $availability = null,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Values::string($data['id'] ?? null) ?? '',
            Values::string($data['description'] ?? null),
            Values::string($data['availability'] ?? null),
            $data,
        );
    }

    /**
     * Options without an availability flag are treated as available.
     */
    public function isAvailable(): bool
    {
        return $this->availability === null || $this->availability === 'AVAILABLE';
    }

    /**
     * Identifier without the classifier prefix, e.g. "DOCUMENT_SENT".
     */
    public function shortId(): string
    {
        $position = strrpos($this->id, ':');

        return $position === false ? $this->id : substr($this->id, $position + 1);
    }
}
