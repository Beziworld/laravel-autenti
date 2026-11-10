<?php

declare(strict_types=1);

use Beziworld\Autenti\AutentiConfig;
use Beziworld\Autenti\Events\ApprovalConsented;
use Beziworld\Autenti\Events\AutentiEventReceived;
use Beziworld\Autenti\Events\DocumentCompleted;
use Beziworld\Autenti\Events\DocumentExpired;
use Beziworld\Autenti\Events\DocumentRejected;
use Beziworld\Autenti\Events\DocumentWithdrawn;
use Beziworld\Autenti\Events\ReviewCompleted;
use Beziworld\Autenti\Events\SigningCompleted;
use Beziworld\Autenti\Events\SigningHandedOver;
use Beziworld\Autenti\Exceptions\InvalidCallback;
use Beziworld\Autenti\Facades\Autenti;
use Beziworld\Autenti\Support\Base64Url;
use Beziworld\Autenti\Tests\TestCase;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

const KID = 'autenti-accept-test';

/**
 * Detached JWS over the body, signed like Autenti callbacks.
 */
function detachedSignature(string $body, string $kid = KID, ?string $key = null): string
{
    $header = Base64Url::encode(json_encode(['kid' => $kid, 'alg' => 'RS256']));
    openssl_sign($header . '.' . Base64Url::encode($body), $signature, $key ?? TestCase::callbackPrivateKey(), OPENSSL_ALGO_SHA256);

    return $header . '..' . Base64Url::encode($signature);
}

function fakeJwks(): void
{
    Http::fake([AutentiConfig::ACCEPTANCE_JWKS => Http::response(['keys' => [TestCase::jwk(TestCase::callbackPrivateKey(), KID)]])]);
}

function callbackBody(string $type, string $documentId = 'DOCUMENT_PROCESS:3429836f-ba99-4b4c-a689-37f5e9f52106'): string
{
    return json_encode([[
        'id' => 'EVENT-AUTENTI:3429836f-' . $type,
        'actor' => ['id' => null, 'type' => 'SYSTEM'],
        'eventType' => 'EVENT_CLASSIFIER-UNIQUE_TYPE:' . $type,
        'object' => ['id' => $documentId, 'type' => 'EVENT_OBJECT-TYPE:DOCUMENT_PROCESS'],
        'timestamp' => '2025-05-29T12:14:10.058Z',
    ]], JSON_UNESCAPED_SLASHES);
}

function postCallback(TestCase $test, string $body, ?string $signature, string $path = '/autenti/callback/default'): TestResponse
{
    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== null) {
        $headers['HTTP_X_JWS_SIGNATURE'] = $signature;
    }

    return $test->call('POST', $path, [], [], [], $headers, $body);
}

function fakeCallbackEvents(): void
{
    EventFacade::fake([AutentiEventReceived::class, DocumentCompleted::class, DocumentRejected::class, DocumentWithdrawn::class, DocumentExpired::class, SigningCompleted::class, ApprovalConsented::class, ReviewCompleted::class, SigningHandedOver::class]);
}

it('verifies the documented callback example with the published production key', function (): void {
    Http::fake([AutentiConfig::PRODUCTION_JWKS => Http::response(autentiFixture('production-webhook.jwks'))]);

    $events = Autenti::using(['client_id' => 'p', 'client_secret' => 's', 'grant' => 'client_credentials', 'environment' => 'production'])
        ->parseCallback(autentiRawFixture('documented-callback'), trim((string) file_get_contents(__DIR__ . '/../Fixtures/documented-callback.signature')));

    expect($events)->toHaveCount(1)
        ->and($events[0]->shortType())->toBe('DOCUMENT_PROCESS_COMPLETED')
        ->and($events[0]->actorType)->toBe('SYSTEM')
        ->and($events[0]->documentId())->toBe('DOCUMENT_PROCESS:19d3c0ad-1a9d-4ea0-92d9-44b93ff67191');
});

it('rejects the documented signature over a modified body', function (): void {
    Http::fake([AutentiConfig::PRODUCTION_JWKS => Http::response(autentiFixture('production-webhook.jwks'))]);

    Autenti::using(['client_id' => 'p', 'client_secret' => 's', 'grant' => 'client_credentials', 'environment' => 'production'])
        ->parseCallback(str_replace('COMPLETED', 'REJECTED', autentiRawFixture('documented-callback')), trim((string) file_get_contents(__DIR__ . '/../Fixtures/documented-callback.signature')));
})->throws(InvalidCallback::class, 'does not match');

it('dispatches events for an authentic callback', function (string $type, string $event): void {
    fakeJwks();
    fakeCallbackEvents();
    $body = callbackBody($type);

    postCallback($this, $body, detachedSignature($body))->assertOk();

    EventFacade::assertDispatched(AutentiEventReceived::class, fn(AutentiEventReceived $received): bool => $received->connection === 'default'
        && $received->event->shortType() === $type
        && $received->documentId() === 'DOCUMENT_PROCESS:3429836f-ba99-4b4c-a689-37f5e9f52106');
    EventFacade::assertDispatched($event);
})->with([
    'completed' => ['DOCUMENT_PROCESS_COMPLETED', DocumentCompleted::class],
    'rejected' => ['DOCUMENT_PROCESS_REJECTED', DocumentRejected::class],
    'withdrawn' => ['DOCUMENT_PROCESS_WITHDRAWN', DocumentWithdrawn::class],
    'expired' => ['DOCUMENT_PROCESS_EXPIRED', DocumentExpired::class],
    'signed' => ['SIGNING_PROCESS_COMPLETED', SigningCompleted::class],
    'approved' => ['APPROVAL_PROCESS_CONSENTED', ApprovalConsented::class],
    'reviewed' => ['REVIEW_PROCESS_COMPLETED', ReviewCompleted::class],
    'handed over' => ['DOCUMENT_SIGNING_HANDED_OVER', SigningHandedOver::class],
]);

it('dispatches only the generic event for unknown types', function (): void {
    fakeJwks();
    fakeCallbackEvents();
    $body = callbackBody('SOMETHING_NEW');

    postCallback($this, $body, detachedSignature($body))->assertOk();

    EventFacade::assertDispatched(AutentiEventReceived::class);
    EventFacade::assertNotDispatched(DocumentCompleted::class);
});

it('rejects callbacks signed with another key', function (): void {
    fakeJwks();
    fakeCallbackEvents();
    $body = callbackBody('DOCUMENT_PROCESS_COMPLETED');

    postCallback($this, $body, detachedSignature($body, KID, TestCase::clientPrivateKey()))->assertForbidden();

    EventFacade::assertNothingDispatched();
});

it('rejects unsigned callbacks, unknown keys and changed bodies', function (): void {
    fakeJwks();
    fakeCallbackEvents();
    $body = callbackBody('DOCUMENT_PROCESS_COMPLETED');

    postCallback($this, $body, null)->assertForbidden();
    postCallback($this, $body, detachedSignature($body, 'rotated-key'))->assertForbidden();
    postCallback($this, str_replace('COMPLETED', 'REJECTED', $body), detachedSignature($body))->assertForbidden();
    postCallback($this, $body, 'not-a-jws')->assertForbidden();

    EventFacade::assertNothingDispatched();
});

it('answers malformed bodies with HTTP 400', function (): void {
    fakeJwks();
    fakeCallbackEvents();

    foreach (['not json', '[]', '[{"id":"x"}]'] as $body) {
        postCallback($this, $body, detachedSignature($body))->assertStatus(400);
    }

    EventFacade::assertNothingDispatched();
});

it('caches the key set and refetches it once for an unknown key', function (): void {
    fakeJwks();
    fakeCallbackEvents();
    $body = callbackBody('DOCUMENT_PROCESS_COMPLETED');

    postCallback($this, $body, detachedSignature($body))->assertOk();
    postCallback($this, $body, detachedSignature($body))->assertOk();
    postCallback($this, $body, detachedSignature($body, 'unknown'))->assertForbidden();

    Http::assertSentCount(2);
});

it('accepts a single event object and an embedded payload', function (): void {
    fakeJwks();
    $body = json_encode(json_decode(callbackBody('DOCUMENT_PROCESS_COMPLETED'), true)[0]);
    $detached = detachedSignature($body);
    [$header, , $signature] = explode('.', $detached);

    expect(Autenti::parseCallback($body, $header . '.' . Base64Url::encode($body) . '.' . $signature)[0]->shortType())->toBe('DOCUMENT_PROCESS_COMPLETED');
});

it('can skip signature verification explicitly', function (): void {
    fakeCallbackEvents();
    config()->set('autenti.callbacks.verify_signature', false);
    Http::fake();

    postCallback($this, callbackBody('DOCUMENT_PROCESS_COMPLETED'), null)->assertOk();

    EventFacade::assertDispatched(DocumentCompleted::class);
});

it('rejects callbacks for unknown connections', function (): void {
    $body = callbackBody('DOCUMENT_PROCESS_COMPLETED');
    Http::fake();

    postCallback($this, $body, detachedSignature($body), '/autenti/callback/unknown')->assertForbidden();
});

it('routes callbacks of runtime connections', function (): void {
    fakeJwks();
    fakeCallbackEvents();
    Autenti::resolveConnectionsUsing(fn(string $name): ?array => $name === 'tenant-7' ? ['client_id' => 't', 'client_secret' => 's', 'grant' => 'client_credentials'] : null);
    $body = callbackBody('DOCUMENT_PROCESS_COMPLETED');

    postCallback($this, $body, detachedSignature($body), '/autenti/callback/tenant-7')->assertOk();

    EventFacade::assertDispatched(DocumentCompleted::class, fn(DocumentCompleted $event): bool => $event->connection === 'tenant-7');
});
