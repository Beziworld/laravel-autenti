<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Exceptions\InvalidRequest;

/**
 * Draft of a document process sent to POST /document-processes.
 *
 * Creating a document does not send it: files are added separately and
 * the document is sent with the DOCUMENT_SENT action (AutentiClient::send()
 * or AutentiClient::sendDocument()). language is the language of the
 * e-mails to the participants and defaults to the connection language.
 */
final readonly class DocumentRequest
{
    /**
     * @param list<ParticipantRequest> $participants
     * @param list<Constraint> $constraints Document level constraints, e.g. Constraint::expiration().
     * @param list<array<string, mixed>> $tags
     *
     * @throws InvalidRequest When the title is empty.
     */
    public function __construct(
        public string $title,
        public array $participants = [],
        public ?string $description = null,
        public ?string $language = null,
        public array $constraints = [],
        public array $tags = [],
    ) {
        if (trim($title) === '') {
            throw InvalidRequest::because('document title must not be empty');
        }
    }

    /**
     * DocumentProcessInput of the API.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $defaultLanguage = 'pl'): array
    {
        $data = [
            'title' => trim($this->title),
            'processLanguage' => strtolower($this->language ?? $defaultLanguage),
        ];

        if ($this->description !== null && $this->description !== '') {
            $data['description'] = $this->description;
        }

        if ($this->participants !== []) {
            $data['parties'] = array_map(static fn(ParticipantRequest $participant): array => $participant->toArray(), $this->participants);
        }

        if ($this->constraints !== []) {
            $data['constraints'] = array_map(static fn(Constraint $constraint): array => $constraint->toArray(), $this->constraints);
        }

        if ($this->tags !== []) {
            $data['tags'] = $this->tags;
        }

        return $data;
    }
}
