<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Enums\DocumentStatus;
use Beziworld\Autenti\Enums\ParticipantRole;
use Beziworld\Autenti\Support\Values;
use Carbon\CarbonImmutable;

/**
 * Document process ("envelope"): title, status, participants, files and flags.
 *
 * id has the form "DOCUMENT_PROCESS:{uuid}". status is null for values
 * outside the known dictionary; statusValue keeps the raw value.
 */
final readonly class DocumentProcess
{
    /**
     * @param list<Participant> $participants
     * @param list<FileInfo> $files
     * @param list<string> $flags
     * @param list<array<array-key, mixed>> $tags
     * @param list<array<array-key, mixed>> $constraints
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public string $id,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $language = null,
        public ?string $statusValue = null,
        public ?DocumentStatus $status = null,
        public array $participants = [],
        public array $files = [],
        public array $flags = [],
        public array $tags = [],
        public array $constraints = [],
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $modifiedAt = null,
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $timezone = 'Europe/Warsaw'): self
    {
        $status = Values::string($data['status'] ?? null);
        $flags = [];

        foreach (Values::array($data['flags'] ?? null) as $flag) {
            $flag = is_array($flag) ? ($flag['id'] ?? null) : $flag;

            if (is_string($flag)) {
                $flags[] = $flag;
            }
        }

        return new self(
            id: Values::string($data['id'] ?? null) ?? '',
            title: Values::string($data['title'] ?? null),
            description: Values::string($data['description'] ?? null),
            language: Values::string($data['processLanguage'] ?? null),
            statusValue: $status,
            status: $status !== null ? DocumentStatus::tryFrom($status) : null,
            participants: Participant::listFromArray(Values::array($data['parties'] ?? null), $timezone),
            files: FileInfo::listFromArray(Values::array($data['files'] ?? null), $timezone),
            flags: $flags,
            tags: array_values(array_filter(Values::array($data['tags'] ?? null), is_array(...))),
            constraints: array_values(array_filter(Values::array($data['constraints'] ?? null), is_array(...))),
            createdAt: Values::date($data['createdAt'] ?? null, $timezone),
            modifiedAt: Values::date($data['modifiedAt'] ?? null, $timezone),
            raw: $data,
        );
    }

    /**
     * Identifier without the "DOCUMENT_PROCESS:" prefix.
     */
    public function uuid(): string
    {
        return str_starts_with($this->id, 'DOCUMENT_PROCESS:') ? substr($this->id, 17) : $this->id;
    }

    /**
     * @return list<Participant>
     */
    public function participantsWithRole(ParticipantRole $role): array
    {
        return array_values(array_filter($this->participants, static fn(Participant $participant): bool => $participant->role === $role));
    }

    /**
     * @return list<Participant>
     */
    public function signers(): array
    {
        return $this->participantsWithRole(ParticipantRole::Signer);
    }

    /**
     * First participant with the given e-mail address, optionally limited to a role.
     */
    public function participant(string $email, ?ParticipantRole $role = null): ?Participant
    {
        foreach ($this->participants as $participant) {
            if ($participant->email !== null && strcasecmp($participant->email, $email) === 0 && ($role === null || $participant->role === $role)) {
                return $participant;
            }
        }

        return null;
    }

    /**
     * The signed result file, when the process produced one.
     */
    public function signedFile(): ?FileInfo
    {
        foreach ($this->files as $file) {
            if ($file->isSigned()) {
                return $file;
            }
        }

        return null;
    }

    public function isCompleted(): bool
    {
        return $this->status === DocumentStatus::Completed;
    }
}
