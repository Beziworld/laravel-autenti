<?php

declare(strict_types=1);

use Beziworld\Autenti\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Raw JSON fixture body.
 */
function autentiRawFixture(string $name): string
{
    return trim((string) file_get_contents(__DIR__ . '/Fixtures/' . $name . '.json'));
}

/**
 * Decoded JSON fixture from tests/Fixtures.
 *
 * @return array<array-key, mixed>
 */
function autentiFixture(string $name): array
{
    return json_decode(autentiRawFixture($name), true, 64, JSON_THROW_ON_ERROR);
}

/**
 * Token endpoint stub as documented (expires_in 3600).
 *
 * @return array<string, mixed>
 */
function autentiToken(string $token = Beziworld\Autenti\Tests\TestCase::TOKEN): array
{
    return ['access_token' => $token, 'token_type' => 'bearer', 'expires_in' => 3600, 'refresh_token' => 'refresh-1'];
}

/**
 * Fakes the token endpoint and the given API routes.
 *
 * @param array<string, mixed> $routes
 */
function fakeAutenti(array $routes): void
{
    Illuminate\Support\Facades\Http::fake(['*/auth/token' => Illuminate\Support\Facades\Http::response(autentiToken())] + $routes);
}

/**
 * Recorded requests other than token requests.
 *
 * @return list<array{0: Illuminate\Http\Client\Request, 1: mixed}>
 */
function apiRequests(): array
{
    return array_values(array_filter(Illuminate\Support\Facades\Http::recorded()->all(), fn(array $pair): bool => ! str_ends_with($pair[0]->url(), '/auth/token')));
}
