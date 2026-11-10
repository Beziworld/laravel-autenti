<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Events;

use Beziworld\Autenti\Data\Event;

/**
 * Dispatched for every event of an authentic callback, before the specific events.
 *
 * Autenti repeats a callback until it receives HTTP 2xx (immediately,
 * after 30 minutes, 6 hours and 24 hours), so listeners must be idempotent;
 * the event id is stable across deliveries.
 */
final readonly class AutentiEventReceived
{
    /**
     * @param string|null $connection Name of the connection that received the callback.
     */
    public function __construct(
        public Event $event,
        public ?string $connection = null,
    ) {}

    /**
     * Identifier of the document process, e.g. "DOCUMENT_PROCESS:3429836f-ba99-4b4c-a689-37f5e9f52106".
     */
    public function documentId(): ?string
    {
        return $this->event->documentId();
    }
}
