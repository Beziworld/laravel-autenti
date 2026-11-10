<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Enums\FilePurpose;
use Beziworld\Autenti\Support\Values;
use Carbon\CarbonImmutable;

/**
 * Metadata of a file of a document process, e.g. the source PDF or the signed result.
 */
final readonly class FileInfo
{
    /**
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public ?string $filename = null,
        public ?string $purposeValue = null,
        public ?FilePurpose $purpose = null,
        public ?string $mimeType = null,
        public ?string $version = null,
        public ?string $description = null,
        public ?CarbonImmutable $modifiedAt = null,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $timezone = 'Europe/Warsaw'): self
    {
        $purpose = Values::string($data['filePurpose'] ?? null);

        return new self(
            id: Values::string($data['id'] ?? null) ?? '',
            filename: Values::string($data['filename'] ?? null),
            purposeValue: $purpose,
            purpose: $purpose !== null ? FilePurpose::tryFrom($purpose) : null,
            mimeType: Values::string($data['mimeType'] ?? null),
            version: Values::string($data['version'] ?? null),
            description: Values::string($data['description'] ?? null),
            modifiedAt: Values::date($data['modificationTime'] ?? null, $timezone),
            raw: $data,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<self>
     */
    public static function listFromArray(array $data, string $timezone = 'Europe/Warsaw'): array
    {
        $files = [];

        foreach ($data as $file) {
            if (is_array($file)) {
                $files[] = self::fromArray($file, $timezone);
            }
        }

        return $files;
    }

    public function isSigned(): bool
    {
        return $this->purpose === FilePurpose::Signed;
    }
}
