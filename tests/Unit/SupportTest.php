<?php

declare(strict_types=1);

use Beziworld\Autenti\AutentiClient;
use Beziworld\Autenti\Data\Challenge;
use Beziworld\Autenti\Data\Constraint;
use Beziworld\Autenti\Data\DocumentFilter;
use Beziworld\Autenti\Data\DocumentRequest;
use Beziworld\Autenti\Data\ParticipantRequest;
use Beziworld\Autenti\Enums\DocumentStatus;
use Beziworld\Autenti\Enums\ListStatus;
use Beziworld\Autenti\Exceptions\InvalidRequest;
use Beziworld\Autenti\Support\Base64Url;
use Beziworld\Autenti\Support\Jwk;
use Beziworld\Autenti\Support\Jwt;
use Beziworld\Autenti\Tests\TestCase;

it('round trips base64url and accepts standard base64', function (): void {
    $binary = random_bytes(64);

    expect(Base64Url::decode(Base64Url::encode($binary)))->toBe($binary)
        ->and(Base64Url::encode("\xfb\xff"))->toBe('-_8')
        ->and(Base64Url::decode(base64_encode('{"a":1}')))->toBe('{"a":1}')
        ->and(Base64Url::decode('%%%'))->toBeNull();
});

it('converts JWKs to PEM keys that OpenSSL accepts', function (): void {
    $jwk = TestCase::jwk(TestCase::callbackPrivateKey(), 'k');
    $pem = Jwk::toPem($jwk);
    $expected = openssl_pkey_get_details(openssl_pkey_get_private(TestCase::callbackPrivateKey()))['key'];

    expect($pem)->toBe($expected)
        ->and(Jwk::toPem(['kty' => 'EC', 'x' => 'a']))->toBeNull()
        ->and(Jwk::toPem(['kty' => 'RSA', 'n' => '', 'e' => 'AQAB']))->toBeNull();
});

it('converts the published Autenti production key', function (): void {
    $pem = Jwk::toPem(autentiFixture('production-webhook.jwks')['keys'][0]);
    $details = openssl_pkey_get_details(openssl_pkey_get_public((string) $pem));

    expect($details['bits'])->toBe(2048)->and($details['type'])->toBe(OPENSSL_KEYTYPE_RSA);
});

it('signs JWTs with RS512 as well', function (): void {
    $jwt = Jwt::sign(['sub' => 'x'], TestCase::clientPrivateKey(), 'RS512');
    [$header, $payload, $signature] = explode('.', $jwt);
    $public = openssl_pkey_get_details(openssl_pkey_get_private(TestCase::clientPrivateKey()))['key'];

    expect(Jwt::decodeSegment($header))->toBe(['alg' => 'RS512', 'typ' => 'JWT'])
        ->and(Jwt::verify($header . '.' . $payload, (string) Base64Url::decode($signature), $public, 'RS512'))->toBeTrue()
        ->and(Jwt::verify($header . '.' . $payload, (string) Base64Url::decode($signature), $public, 'HS256'))->toBeFalse();
});

it('decodes challenges and builds selection assertions', function (): void {
    $challenge = Challenge::fromHeader('ewogICJjbGFzc2lmaWVycyI6IFsKICAgICJDSEFMTEVOR0VfQ0xBU1NJRklFUi1VTklRVUVfVFlQRTpBVVRFTlRJX1BBU1NXT1JEIiwKICAgICJDSEFMTEVOR0VfQ0xBU1NJRklFUi1VU0VSX0lOVEVSQUNUSU9OX1RZUEU6VVNFUl9JTlBVVCIKICBdCn0');

    expect($challenge?->classifiers)->toBe(['CHALLENGE_CLASSIFIER-UNIQUE_TYPE:AUTENTI_PASSWORD', 'CHALLENGE_CLASSIFIER-USER_INTERACTION_TYPE:USER_INPUT'])
        ->and(Challenge::fromHeader(''))->toBeNull()
        ->and(Challenge::fromHeader('bm90IGpzb24'))->toBeNull()
        ->and(Challenge::selection(Challenge::ACTION_SELECTION, ['A']))->toBe(['classifiers' => [Challenge::ACTION_SELECTION], 'attributes' => ['selectedIds' => ['A']]]);
});

it('normalises document identifiers', function (): void {
    expect(AutentiClient::documentId(' 3429836f '))->toBe('DOCUMENT_PROCESS:3429836f')
        ->and(AutentiClient::documentId('DOCUMENT_PROCESS:abc'))->toBe('DOCUMENT_PROCESS:abc')
        ->and(fn() => AutentiClient::documentId(' '))->toThrow(InvalidArgumentException::class);
});

it('validates participants, documents and filters', function (): void {
    expect(fn() => new ParticipantRequest('not-an-email', 'John', 'Doe'))->toThrow(InvalidRequest::class, 'e-mail')
        ->and(fn() => new ParticipantRequest('a@b.pl', ' ', 'Doe'))->toThrow(InvalidRequest::class, 'name')
        ->and(fn() => ParticipantRequest::signer('a@b.pl', 'A', 'B', organizationTaxId: '123'))->toThrow(InvalidRequest::class, 'NIP')
        ->and(fn() => new DocumentRequest('  '))->toThrow(InvalidRequest::class, 'title')
        ->and(fn() => new DocumentFilter(limit: 129))->toThrow(InvalidRequest::class, '128')
        ->and(fn() => new DocumentFilter(offset: 300))->toThrow(InvalidRequest::class, '256')
        ->and(fn() => Constraint::priority(0))->toThrow(InvalidRequest::class, 'priority')
        ->and(fn() => Constraint::phoneVerification('12'))->toThrow(InvalidRequest::class, 'phone')
        ->and(fn() => Constraint::returnUrl('/relative'))->toThrow(InvalidRequest::class, 'absolute');
});

it('builds the minimal documented draft and extra constraints', function (): void {
    $participant = ParticipantRequest::reviewer('a@b.pl', 'Anna', 'Nowak')->with(new Constraint(['CONSTRAINT-UNIQUE_TYPE:VISUALISATION']));

    expect((new DocumentRequest('example title', language: 'EN'))->toArray())->toBe(['title' => 'example title', 'processLanguage' => 'en'])
        ->and($participant->toArray()['constraints'][0]['classifiers'])->toBe(['CONSTRAINT-UNIQUE_TYPE:VISUALISATION'])
        ->and(json_encode($participant->toArray()['constraints'][0]['attributes']))->toBe('{}')
        ->and(Constraint::phoneVerification('+48 601 123 456')->attributes)->toBe(['phoneNumber' => '+48601123456'])
        ->and((new DocumentFilter(status: ListStatus::Draft, offset: 500, sharedDocuments: true))->toQuery())->toBe(['status' => 'DRAFT', 'offset' => 500, 'sharedDocuments' => 'true']);
});

it('classifies final document statuses', function (): void {
    expect(DocumentStatus::Completed->isFinal())->toBeTrue()
        ->and(DocumentStatus::Withdrawn->isFinal())->toBeTrue()
        ->and(DocumentStatus::Processing->isFinal())->toBeFalse()
        ->and(DocumentStatus::tryFrom('NEW_STATUS'))->toBeNull();
});
