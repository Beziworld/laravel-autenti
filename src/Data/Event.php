<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Support\Values;
use Carbon\CarbonImmutable;

/**
 * Event registered on a document process: an action result, a callback notification or a participant event.
 *
 * type is the full event type, e.g.
 * "EVENT_CLASSIFIER-UNIQUE_TYPE:DOCUMENT_PROCESS_COMPLETED"; shortType()
 * strips the prefix. objectId is usually the document process identifier.
 */
final readonly class Event
{
    public const string PREFIX = 'EVENT_CLASSIFIER-UNIQUE_TYPE:';

    public const string DOCUMENT_SENT = self::PREFIX . 'DOCUMENT_SENT';

    public const string DOCUMENT_WITHDRAWAL = self::PREFIX . 'DOCUMENT_WITHDRAWAL';

    public const string DOCUMENT_REMINDER_SENT = self::PREFIX . 'DOCUMENT_REMINDER_SENT';

    public const string DOCUMENT_PROCESS_COMPLETED = self::PREFIX . 'DOCUMENT_PROCESS_COMPLETED';

    public const string DOCUMENT_PROCESS_REJECTED = self::PREFIX . 'DOCUMENT_PROCESS_REJECTED';

    public const string DOCUMENT_PROCESS_WITHDRAWN = self::PREFIX . 'DOCUMENT_PROCESS_WITHDRAWN';

    public const string DOCUMENT_PROCESS_EXPIRED = self::PREFIX . 'DOCUMENT_PROCESS_EXPIRED';

    public const string SIGNING_PROCESS_COMPLETED = self::PREFIX . 'SIGNING_PROCESS_COMPLETED';

    public const string APPROVAL_PROCESS_CONSENTED = self::PREFIX . 'APPROVAL_PROCESS_CONSENTED';

    public const string REVIEW_PROCESS_COMPLETED = self::PREFIX . 'REVIEW_PROCESS_COMPLETED';

    public const string DOCUMENT_SIGNING_HANDED_OVER = self::PREFIX . 'DOCUMENT_SIGNING_HANDED_OVER';

    public const string SIGNATURE_APPLICATION = self::PREFIX . 'SIGNATURE_APPLICATION';

    public const string SIGNATURE_REJECTION = self::PREFIX . 'SIGNATURE_REJECTION';

    /**
     * @param list<string> $classifiers
     * @param array<array-key, mixed> $attributes
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public string $type,
        public ?CarbonImmutable $timestamp = null,
        public array $classifiers = [],
        public ?string $actorId = null,
        public ?string $actorType = null,
        public ?string $objectId = null,
        public ?string $objectType = null,
        public array $attributes = [],
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $timezone = 'Europe/Warsaw'): self
    {
        $actor = Values::array($data['actor'] ?? null);
        $object = Values::array($data['object'] ?? null);

        return new self(
            id: Values::string($data['id'] ?? null) ?? '',
            type: Values::string($data['eventType'] ?? null) ?? '',
            timestamp: Values::date($data['timestamp'] ?? null, $timezone),
            classifiers: Values::strings($data['classifiers'] ?? null),
            actorId: Values::string($actor['id'] ?? null),
            actorType: Values::string($actor['type'] ?? null),
            objectId: Values::string($object['id'] ?? null),
            objectType: Values::string($object['type'] ?? null),
            attributes: Values::array($data['attributes'] ?? null),
            raw: $data,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<self>
     */
    public static function listFromArray(array $data, string $timezone = 'Europe/Warsaw'): array
    {
        $events = [];

        foreach ($data as $event) {
            if (is_array($event)) {
                $events[] = self::fromArray($event, $timezone);
            }
        }

        return $events;
    }

    /**
     * Event type without the classifier prefix, e.g. "DOCUMENT_PROCESS_COMPLETED".
     */
    public function shortType(): string
    {
        return str_starts_with($this->type, self::PREFIX) ? substr($this->type, strlen(self::PREFIX)) : $this->type;
    }

    public function is(string $type): bool
    {
        return $this->type === $type || $this->shortType() === $type;
    }

    /**
     * Identifier of the document process the event refers to, when the object is a document process.
     */
    public function documentId(): ?string
    {
        return $this->objectId !== null && str_starts_with($this->objectId, 'DOCUMENT_PROCESS:') ? $this->objectId : null;
    }
}
