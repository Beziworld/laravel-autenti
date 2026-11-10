<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Events;

use Beziworld\Autenti\Data\Event;

/**
 * Dispatched when one of the participants signed the document (SIGNING_PROCESS_COMPLETED).
 */
final readonly class SigningCompleted
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
