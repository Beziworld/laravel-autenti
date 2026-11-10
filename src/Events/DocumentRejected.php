<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Events;

use Beziworld\Autenti\Data\Event;

/**
 * Dispatched when a participant rejected the document (DOCUMENT_PROCESS_REJECTED).
 */
final readonly class DocumentRejected
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
