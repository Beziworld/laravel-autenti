<?php

declare(strict_types=1);

namespace Beziworld\Autenti\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Tolerant readers of scalar values from decoded Autenti payloads.
 *
 * @internal
 */
final class Values
{
    public static function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    public static function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * List of strings, skipping other values.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        return array_values(array_filter(self::array($value), is_string(...)));
    }

    /**
     * Parses an ISO 8601 date and converts it to the given time zone.
     */
    public static function date(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), 'UTC')->setTimezone($timezone);
        } catch (Throwable) {
            return null;
        }
    }
}
