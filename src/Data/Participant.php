<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Enums\ParticipantRole;
use Beziworld\Autenti\Enums\ParticipationStatus;
use Beziworld\Autenti\Support\Values;

/**
 * Party of a document process with its role and participation status.
 *
 * partyId identifies the party, e.g. "PARTY-EMAIL:john.doe@example.com"
 * in drafts or "PARTY-PUBLIC_SIGNING_PROCESS_ID:83961e68" in sent
 * documents; use it for action links and removal.
 */
final readonly class Participant
{
    /**
     * @param list<array<array-key, mixed>> $constraints
     * @param list<Event> $events
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public ?string $partyId,
        public ?string $email = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $name = null,
        public ?string $roleValue = null,
        public ?ParticipantRole $role = null,
        public ?string $statusValue = null,
        public ?ParticipationStatus $status = null,
        public bool $currentUser = false,
        public ?string $organizationName = null,
        public ?string $organizationTaxId = null,
        public array $constraints = [],
        public array $events = [],
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data, string $timezone = 'Europe/Warsaw'): self
    {
        $party = Values::array($data['party'] ?? null);
        $role = Values::string($data['role'] ?? null);
        $status = Values::string($data['participationStatus'] ?? null);
        $email = null;

        foreach (Values::array($party['contacts'] ?? null) as $contact) {
            if (is_array($contact) && ($contact['type'] ?? null) === 'CONTACT-TYPE:EMAIL') {
                $email = Values::string(Values::array($contact['attributes'] ?? null)['email'] ?? null);
            }
        }

        $organization = null;

        foreach (Values::array($party['relationships'] ?? null) as $relationship) {
            if (is_array($relationship) && is_array($relationship['party'] ?? null)) {
                $organization = $relationship['party'];

                break;
            }
        }

        $taxId = null;

        foreach (Values::array($organization['extIds'] ?? null) as $extId) {
            if (is_array($extId) && ($extId['identificationSpace'] ?? null) === 'TAXID-PL-NIP') {
                $taxId = Values::string($extId['identifier'] ?? null);
            }
        }

        $constraints = array_values(array_filter(Values::array($data['constraints'] ?? null), is_array(...)));

        return new self(
            partyId: Values::string($party['id'] ?? null),
            email: $email,
            firstName: Values::string($party['firstName'] ?? null),
            lastName: Values::string($party['lastName'] ?? null),
            name: Values::string($party['name'] ?? null),
            roleValue: $role,
            role: $role !== null ? ParticipantRole::tryFrom($role) : null,
            statusValue: $status,
            status: $status !== null ? ParticipationStatus::tryFrom($status) : null,
            currentUser: Values::bool($data['currentUser'] ?? null) ?? false,
            organizationName: Values::string($organization['name'] ?? null),
            organizationTaxId: $taxId,
            constraints: $constraints,
            events: Event::listFromArray(Values::array($data['events'] ?? null), $timezone),
            raw: $data,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return list<self>
     */
    public static function listFromArray(array $data, string $timezone = 'Europe/Warsaw'): array
    {
        $participants = [];

        foreach ($data as $participant) {
            if (is_array($participant)) {
                $participants[] = self::fromArray($participant, $timezone);
            }
        }

        return $participants;
    }

    public function hasCompleted(): bool
    {
        return $this->status === ParticipationStatus::Completed;
    }
}
