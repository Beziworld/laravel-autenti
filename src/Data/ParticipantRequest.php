<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Enums\ParticipantRole;
use Beziworld\Autenti\Enums\SignatureType;
use Beziworld\Autenti\Exceptions\InvalidRequest;

/**
 * Participant (recipient) added to a document process.
 *
 * The factories cover the documented roles. A participant acting for a
 * company is described with the organisation name and its NIP, which
 * Autenti stores as a MEMBER relationship of the person.
 */
final readonly class ParticipantRequest
{
    /**
     * @param list<Constraint> $constraints
     *
     * @throws InvalidRequest When the e-mail address or the names are invalid.
     */
    public function __construct(
        public string $email,
        public string $firstName,
        public string $lastName,
        public ParticipantRole $role = ParticipantRole::Signer,
        public array $constraints = [],
        public ?string $organizationName = null,
        public ?string $organizationTaxId = null,
        public ?string $position = null,
    ) {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidRequest::because(sprintf('"%s" is not a valid participant e-mail address', $email));
        }

        if (trim($firstName) === '' || trim($lastName) === '') {
            throw InvalidRequest::because('participant first and last name must not be empty');
        }

        if ($organizationTaxId !== null && preg_match('/^\d{10}$/', (string) preg_replace('/\D+/', '', $organizationTaxId)) !== 1) {
            throw InvalidRequest::because('organisation NIP must have 10 digits');
        }
    }

    /**
     * Signer with the requested signature type, optional signing order position, SMS verification and return URL.
     */
    public static function signer(
        string $email,
        string $firstName,
        string $lastName,
        SignatureType $signatureType = SignatureType::Basic,
        ?int $priority = null,
        ?string $phoneVerification = null,
        ?string $returnUrl = null,
        ?string $organizationName = null,
        ?string $organizationTaxId = null,
        ?string $position = null,
    ): self {
        $constraints = [Constraint::signatureType($signatureType)];

        if ($priority !== null) {
            $constraints[] = Constraint::priority($priority);
        }

        if ($phoneVerification !== null) {
            $constraints[] = Constraint::phoneVerification($phoneVerification);
        }

        if ($returnUrl !== null) {
            $constraints[] = Constraint::returnUrl($returnUrl);
        }

        return new self($email, $firstName, $lastName, ParticipantRole::Signer, $constraints, $organizationName, $organizationTaxId, $position);
    }

    public static function approver(string $email, string $firstName, string $lastName, ?int $priority = null): self
    {
        return new self($email, $firstName, $lastName, ParticipantRole::Approver, $priority !== null ? [Constraint::priority($priority)] : []);
    }

    public static function reviewer(string $email, string $firstName, string $lastName, ?int $priority = null): self
    {
        return new self($email, $firstName, $lastName, ParticipantRole::Reviewer, $priority !== null ? [Constraint::priority($priority)] : []);
    }

    public static function viewer(string $email, string $firstName, string $lastName): self
    {
        return new self($email, $firstName, $lastName, ParticipantRole::Viewer);
    }

    /**
     * A copy with an additional constraint.
     */
    public function with(Constraint $constraint): self
    {
        return new self(
            $this->email,
            $this->firstName,
            $this->lastName,
            $this->role,
            [...$this->constraints, $constraint],
            $this->organizationName,
            $this->organizationTaxId,
            $this->position,
        );
    }

    /**
     * DocumentProcessPartyInput of the API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $party = [
            'firstName' => trim($this->firstName),
            'lastName' => trim($this->lastName),
            'contacts' => [
                ['type' => 'CONTACT-TYPE:EMAIL', 'attributes' => ['email' => $this->email]],
            ],
        ];

        if ($this->organizationName !== null || $this->organizationTaxId !== null) {
            $organization = array_filter(['name' => $this->organizationName], static fn(?string $value): bool => $value !== null);

            if ($this->organizationTaxId !== null) {
                $organization['extIds'] = [[
                    'identificationSpace' => 'TAXID-PL-NIP',
                    'identifier' => preg_replace('/\D+/', '', $this->organizationTaxId),
                ]];
            }

            $relationship = ['type' => 'PARTY_RELATIONSHIP-TYPE:MEMBER', 'party' => $organization];

            if ($this->position !== null) {
                $relationship['attributes'] = ['relationshipDescription' => $this->position];
            }

            $party['relationships'] = [$relationship];
        }

        $data = ['party' => $party, 'role' => $this->role->value];

        if ($this->constraints !== []) {
            $data['constraints'] = array_map(static fn(Constraint $constraint): array => $constraint->toArray(), $this->constraints);
        }

        return $data;
    }
}
