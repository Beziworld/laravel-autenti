<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Data;

use Beziworld\Autenti\Support\Base64Url;
use Beziworld\Autenti\Support\Values;

/**
 * Challenge sent by Autenti in the X-Challenge header (base64 encoded JSON).
 *
 * Classifiers identify the challenge, e.g.
 * "CHALLENGE_CLASSIFIER-UNIQUE_TYPE:ACTION_SELECTION" and
 * "CHALLENGE_CLASSIFIER-USER_INTERACTION_TYPE:SELECTION"; attributes
 * carry the options or other details.
 */
final readonly class Challenge
{
    public const string ACTION_SELECTION = 'CHALLENGE_CLASSIFIER-UNIQUE_TYPE:ACTION_SELECTION';

    public const string REMINDER_PARTY_SELECTION = 'CHALLENGE_CLASSIFIER-UNIQUE_TYPE:DOCUMENT_REMINDER_PARTY_SELECTION';

    /**
     * @param list<string> $classifiers
     * @param array<array-key, mixed> $attributes
     * @param array<array-key, mixed> $raw
     */
    public function __construct(
        public array $classifiers,
        public array $attributes = [],
        public array $raw = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Values::strings($data['classifiers'] ?? null), Values::array($data['attributes'] ?? null), $data);
    }

    /**
     * Decodes an X-Challenge header value; null when it is not a challenge.
     */
    public static function fromHeader(?string $header): ?self
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $json = Base64Url::decode($header);
        $data = $json !== null ? json_decode($json, true) : null;

        return is_array($data) ? self::fromArray($data) : null;
    }

    public function is(string $classifier): bool
    {
        return in_array($classifier, $this->classifiers, true);
    }

    /**
     * Options of a selection challenge.
     *
     * @return list<ActionOption>
     */
    public function options(): array
    {
        $options = [];

        foreach (Values::array($this->attributes['options'] ?? null) as $option) {
            if (is_array($option)) {
                $options[] = ActionOption::fromArray($option);
            }
        }

        return $options;
    }

    /**
     * Assertion answering a selection challenge with the given option identifiers.
     *
     * @param list<string> $selectedIds
     * @return array{classifiers: list<string>, attributes: array{selectedIds: list<string>}}
     */
    public static function selection(string $classifier, array $selectedIds): array
    {
        return ['classifiers' => [$classifier], 'attributes' => ['selectedIds' => $selectedIds]];
    }
}
