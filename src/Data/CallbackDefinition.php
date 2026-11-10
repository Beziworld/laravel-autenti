<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Support\Values;

/**
 * Callback (webhook) registered for the organisation.
 */
final readonly class CallbackDefinition
{
    public const string ADAPTER_API_V2 = 'CALLBACK_ADAPTER:API_V2';

    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public ?string $url,
        public string $adapterId = self::ADAPTER_API_V2,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Values::string($data['id'] ?? null) ?? '',
            Values::string(Values::array($data['callbackParameters'] ?? null)['callbackUrl'] ?? null),
            Values::string($data['callbackAdapterId'] ?? null) ?? self::ADAPTER_API_V2,
            $data,
        );
    }
}
