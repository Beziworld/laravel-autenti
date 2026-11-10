<?php

declare(strict_types=1);

use Beziworld\Autenti\Exceptions\AuthenticationFailed;
use Beziworld\Autenti\Exceptions\InvalidConfiguration;
use Beziworld\Autenti\Facades\Autenti;
use Beziworld\Autenti\Support\Base64Url;
use Beziworld\Autenti\Support\Jwt;
use Beziworld\Autenti\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Decoded claims and header of a compact JWT, after verifying it with the public key of the private key.
 *
 * @return array{0: array<array-key, mixed>, 1: array<array-key, mixed>}
 */
function verifiedJwt(string $jwt, string $privateKey): array
{
    [$header, $payload, $signature] = explode('.', $jwt);
    $public = openssl_pkey_get_details(openssl_pkey_get_private($privateKey))['key'];

    expect(Jwt::verify($header . '.' . $payload, (string) Base64Url::decode($signature), $public, 'RS256'))->toBeTrue();

    return [Jwt::decodeSegment($header) ?? [], Jwt::decodeSegment($payload) ?? []];
}

it('obtains a token with the JWT bearer grant as documented', function (): void {
    Http::fake(['*/auth/token' => Http::response(autentiToken()), '*/document-processes*' => Http::response([])]);

    Autenti::documents();

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/auth/token')) {
            return false;
        }

        [$header, $claims] = verifiedJwt((string) $request['assertion'], TestCase::clientPrivateKey());

        return $request->url() === TestCase::API . '/auth/token'
            && $request->method() === 'POST'
            && $request->isJson()
            && $request['client_id'] === TestCase::CLIENT_ID
            && $request['client_secret'] === TestCase::CLIENT_SECRET
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && $header === ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'integration-key-1']
            && $claims['aud'] === 'autenti.com'
            && $claims['iss'] === 'example.com'
            && $claims['email'] === TestCase::USER_EMAIL
            && $claims['email_verified'] === true
            && $claims['sub'] === TestCase::USER_EMAIL
            && abs($claims['iat'] - time()) <= 5
            && $claims['exp'] === $claims['iat'] + 300
            && is_string($claims['jti']);
    });

    Http::assertSent(fn(Request $request): bool => str_contains($request->url(), '/document-processes')
        && $request->hasHeader('Authorization', 'Bearer ' . TestCase::TOKEN));
});

it('caches the token between requests', function (): void {
    Http::fake(['*/auth/token' => Http::response(autentiToken()), '*/document-processes*' => Http::response([])]);

    Autenti::documents();
    Autenti::documents();

    Http::assertSentCount(3);
});

it('refreshes the token once after HTTP 401', function (): void {
    Http::fakeSequence('*/auth/token')->push(autentiToken('old'))->push(autentiToken('new'));
    Http::fakeSequence('*/document-processes*')
        ->push(autentiFixture('error-token-invalid'), 401)
        ->push([]);

    expect(Autenti::documents())->toBe([]);

    Http::assertSent(fn(Request $request): bool => $request->hasHeader('Authorization', 'Bearer new'));
});

it('uses the client credentials grant with a secret', function (): void {
    Http::fake(['*/auth/token' => Http::response(autentiToken()), '*/applications/callbacks' => Http::response([])]);

    Autenti::connection('robot')->callbacks();

    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), '/auth/token')
        && $request->data() === ['client_id' => 'robot-client', 'client_secret' => 'robot-secret', 'grant_type' => 'client_credentials']);
});

it('authenticates the client with a JWT assertion', function (): void {
    Http::fake(['*/auth/token' => Http::response(autentiToken()), '*/applications/callbacks' => Http::response([])]);

    Autenti::using([
        'client_id' => 'asserting-client',
        'grant' => 'client_credentials',
        'client_assertion' => true,
        'private_key' => str_replace("\n", '\n', TestCase::clientPrivateKey()),
    ])->callbacks();

    Http::assertSent(function (Request $request): bool {
        if (! str_ends_with($request->url(), '/auth/token')) {
            return false;
        }

        [, $claims] = verifiedJwt((string) $request['client_assertion'], TestCase::clientPrivateKey());

        return $request['grant_type'] === 'client_credentials'
            && $request['client_assertion_type'] === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
            && ! isset($request->data()['client_secret'])
            && $claims['sub'] === 'asserting-client'
            && $claims['aud'] === 'autenti.com';
    });
});

it('reads the private key from a file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'autenti');
    file_put_contents($path, TestCase::clientPrivateKey());
    Http::fake(['*/auth/token' => Http::response(autentiToken())]);

    try {
        expect(Autenti::using(['client_id' => 'x', 'client_secret' => 'y', 'private_key' => $path, 'user_email' => 'other@example.com'])->token())->toBe(TestCase::TOKEN);
    } finally {
        @unlink($path);
    }
});

it('reports rejected credentials with the OAuth error code', function (): void {
    Http::fake(['*/auth/token' => Http::response(['error' => 'invalid_client'], 401)]);

    try {
        Autenti::token();
        $this->fail('Expected AuthenticationFailed.');
    } catch (AuthenticationFailed $exception) {
        expect($exception->error())->toBe('invalid_client')
            ->and($exception->httpStatus())->toBe(401)
            ->and($exception->requiresMultiFactor())->toBeFalse()
            ->and($exception->getMessage())->toBe('Autenti rejected the token request (HTTP 401, invalid_client)');
    }
});

it('explains a multi-factor challenge of the token endpoint', function (): void {
    $challenge = base64_encode('{"classifiers":["CHALLENGE_CLASSIFIER-UNIQUE_TYPE:AUTENTI_PASSWORD","CHALLENGE_CLASSIFIER-USER_INTERACTION_TYPE:USER_INPUT"]}');
    Http::fake(['*/auth/token' => Http::response(['error' => 'insufficient_authentication'], 403, ['X-CHALLENGE' => $challenge])]);

    try {
        Autenti::token();
        $this->fail('Expected AuthenticationFailed.');
    } catch (AuthenticationFailed $exception) {
        expect($exception->requiresMultiFactor())->toBeTrue()
            ->and($exception->challenge()?->is('CHALLENGE_CLASSIFIER-UNIQUE_TYPE:AUTENTI_PASSWORD'))->toBeTrue();
    }
});

it('validates the configuration of each grant', function (array $config, string $message): void {
    expect(fn() => Autenti::using($config))->toThrow(InvalidConfiguration::class, $message);
})->with([
    'no user' => [['user_email' => null, 'connections' => null, 'client_id' => 'x', 'client_secret' => 'y', 'private_key' => 'k'], 'AUTENTI_USER_EMAIL'],
    'no key' => [['client_id' => 'x', 'client_secret' => 'y', 'grant' => 'jwt_bearer', 'user_email' => 'a@b.pl'], 'AUTENTI_PRIVATE_KEY'],
    'unknown grant' => [['client_id' => 'x', 'grant' => 'password'], 'grant'],
    'unknown environment' => [['client_id' => 'x', 'client_secret' => 'y', 'grant' => 'client_credentials', 'environment' => 'sandbox'], 'environment'],
    'secret for client credentials' => [['client_id' => 'x', 'grant' => 'client_credentials'], 'AUTENTI_CLIENT_SECRET'],
]);

it('rejects unusable private keys', function (): void {
    Http::fake();

    Autenti::using(['client_id' => 'x', 'client_secret' => 'y', 'private_key' => '-----BEGIN PRIVATE KEY-----\nbroken\n-----END PRIVATE KEY-----', 'user_email' => 'a@b.pl'])->token();
})->throws(InvalidConfiguration::class, 'OpenSSL could not parse it');
