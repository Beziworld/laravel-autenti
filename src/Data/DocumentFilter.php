<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Enums\ListStatus;
use Beziworld\Autenti\Exceptions\InvalidRequest;
use DateTimeInterface;

/**
 * Filter and page of the document process list.
 *
 * Paginated lists return at most 128 documents (default 64). With the
 * EVERYTHING status the offset may not exceed 256. Text filters are
 * matched as regular expressions by Autenti.
 */
final readonly class DocumentFilter
{
    /**
     * @throws InvalidRequest When the limit or offset is out of the documented range.
     */
    public function __construct(
        public ?ListStatus $status = null,
        public ?int $limit = null,
        public ?int $offset = null,
        public bool $descending = false,
        public ?DateTimeInterface $createdAfter = null,
        public ?DateTimeInterface $createdBefore = null,
        public ?DateTimeInterface $modifiedAfter = null,
        public ?DateTimeInterface $modifiedBefore = null,
        public ?string $search = null,
        public ?string $senderEmail = null,
        public ?string $recipientEmail = null,
        public ?string $recipientLastName = null,
        public ?string $recipientCompanyName = null,
        public ?bool $sharedDocuments = null,
    ) {
        if ($limit !== null && ($limit < 1 || $limit > 128)) {
            throw InvalidRequest::because('document list limit must be between 1 and 128');
        }

        if ($offset !== null && $offset < 0) {
            throw InvalidRequest::because('document list offset must not be negative');
        }

        if ($offset !== null && $offset > 256 && ($status === null || $status === ListStatus::Everything)) {
            throw InvalidRequest::because('document list offset may not exceed 256 for the EVERYTHING status; filter by status instead');
        }
    }

    /**
     * @return array<string, int|string>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->status !== null) {
            $query['status'] = $this->status->value;
        }

        if ($this->limit !== null) {
            $query['limit'] = $this->limit;
        }

        if ($this->offset !== null) {
            $query['offset'] = $this->offset;
        }

        if ($this->descending) {
            $query['orderBy'] = 'MODIFIED';
            $query['sort'] = 'DESCENDING';
        }

        foreach ([
            'createdAfter' => $this->createdAfter,
            'createdBefore' => $this->createdBefore,
            'modifiedAfter' => $this->modifiedAfter,
            'modifiedBefore' => $this->modifiedBefore,
        ] as $key => $date) {
            if ($date !== null) {
                $query[$key] = \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            }
        }

        foreach ([
            'freeTextSearch' => $this->search,
            'senderEmail' => $this->senderEmail,
            'recipientEmail' => $this->recipientEmail,
            'recipientLastName' => $this->recipientLastName,
            'recipientCompanyName' => $this->recipientCompanyName,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $query[$key] = $value;
            }
        }

        if ($this->sharedDocuments !== null) {
            $query['sharedDocuments'] = $this->sharedDocuments ? 'true' : 'false';
        }

        return $query;
    }
}
