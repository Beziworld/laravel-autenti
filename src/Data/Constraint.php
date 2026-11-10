<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Enums\SignatureType;
use Beziworld\Autenti\Exceptions\InvalidRequest;
use DateTimeInterface;

/**
 * Constraint of a participant or of the whole document process.
 *
 * Autenti expresses requirements (signature type, signing order, SMS
 * verification, return URL, expiration) as constraints with classifiers,
 * attributes and the constrained actions. The factories build the
 * documented ones; the constructor accepts any other.
 */
final readonly class Constraint
{
    public const string SIGNATURE_APPLICATION = 'ACTION:SIGNATURE_APPLICATION';

    /**
     * @param list<string> $classifiers
     * @param array<string, mixed> $attributes
     * @param list<string>|null $constrainedActions
     */
    public function __construct(
        public array $classifiers,
        public array $attributes = [],
        public ?array $constrainedActions = null,
    ) {}

    /**
     * Required signature type of a signer (CONSTRAINT-UNIQUE_TYPE:SIGNATURE_TYPE).
     */
    public static function signatureType(SignatureType $type): self
    {
        return new self(['CONSTRAINT-UNIQUE_TYPE:SIGNATURE_TYPE'], ['requiredClassifiers' => [$type->value]], [self::SIGNATURE_APPLICATION]);
    }

    /**
     * Position of the participant in an agreed signing order (1 acts first).
     */
    public static function priority(int $priority): self
    {
        if ($priority < 1) {
            throw InvalidRequest::because('participation priority must be at least 1');
        }

        return new self(['CONSTRAINT-UNIQUE_TYPE:PARTICIPATION_PRIORITY'], ['priority' => $priority], []);
    }

    /**
     * SMS code sent to the number must be confirmed before signing.
     */
    public static function phoneVerification(string $phoneNumber): self
    {
        $normalized = preg_replace('/[^\d+]/', '', $phoneNumber) ?? '';

        if (preg_match('/^\+?\d{9,15}$/', $normalized) !== 1) {
            throw InvalidRequest::because('phone number for SMS verification must have 9 to 15 digits');
        }

        if (! str_starts_with($normalized, '+')) {
            $normalized = strlen($normalized) === 9 ? '+48' . $normalized : '+' . $normalized;
        }

        return new self(['CONSTRAINT-UNIQUE_TYPE:PHONE_NUMBER_VERIFICATION_REQUIRED'], ['phoneNumber' => $normalized], [self::SIGNATURE_APPLICATION]);
    }

    /**
     * URL the participant is redirected to after completing the action in the Autenti UI.
     */
    public static function returnUrl(string $url): self
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw InvalidRequest::because('return URL must be an absolute URL');
        }

        return new self(['CONSTRAINT-UNIQUE_TYPE:UI_INTERACTION_END_RETURN_URL'], ['url' => $url]);
    }

    /**
     * Deadline of the signatures (document level constraint).
     */
    public static function expiration(DateTimeInterface $expiresAt, bool $notifyBeforeExpiration = true): self
    {
        return new self(
            ['CONSTRAINT-UNIQUE_TYPE:EXPIRATION'],
            [
                'expirationTime' => \DateTimeImmutable::createFromInterface($expiresAt)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'notifyBeforeExpiration' => $notifyBeforeExpiration,
            ],
            [self::SIGNATURE_APPLICATION],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->constrainedActions !== null) {
            $data['constrainedActions'] = $this->constrainedActions;
        }

        $data['classifiers'] = $this->classifiers;
        $data['attributes'] = $this->attributes === [] ? new \stdClass() : $this->attributes;

        return $data;
    }
}
